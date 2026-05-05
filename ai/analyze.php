<?php
header('Content-Type: text/html; charset=utf-8');
mb_internal_encoding('UTF-8');
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tax_rules_helper.php';
require_once __DIR__ . '/../includes/TaxStartAI.php';
$tax_rules_text = getActiveTaxRules($pdo);
requireAdvisor();

$advisor = currentUser();
$file_id = (int)($_GET['file_id'] ?? 0);

if ($file_id === 0) {
    redirectTo('advisor/upload.php');
}

// ── Fetch file & verify ownership ───────────────────
$stmt = $pdo->prepare("
    SELECT uf.*, c.full_name AS client_name,
           c.tax_year, c.tax_type, c.notes AS client_notes
    FROM uploaded_files uf
    JOIN clients c ON uf.client_id = c.client_id
    WHERE uf.file_id = ? AND uf.advisor_id = ?
");
$stmt->execute([$file_id, $advisor['id']]);
$file = $stmt->fetch();

if (!$file) {
    redirectTo('advisor/upload.php');
}

// ── Force re-analyze ─────────────────────────────────
if (isset($_GET['force'])) {
    $pdo->prepare("DELETE FROM ai_analysis WHERE file_id = ?")
        ->execute([$file_id]);
    $pdo->prepare("UPDATE uploaded_files SET status='pending' WHERE file_id=?")
        ->execute([$file_id]);
}

// ── Check if already analyzed ────────────────────────
$existing = $pdo->prepare("SELECT * FROM ai_analysis WHERE file_id = ?");
$existing->execute([$file_id]);
$existing = $existing->fetch();

// ── Extract text from file ───────────────────────────
function extractTextFromFile($file_path, $file_type) {
    $full_path = __DIR__ . '/../' . $file_path;

    if (!file_exists($full_path)) {
        return "File not found at: $full_path";
    }

    $text = '';

    switch (strtoupper($file_type)) {

        case 'PDF':
            $content = file_get_contents($full_path);

            // Method 1: BT...ET blocks
            preg_match_all('/BT\s*(.*?)\s*ET/s', $content, $matches);
            foreach ($matches[1] as $block) {
                preg_match_all('/\(([^)]{1,100})\)\s*Tj/s',
                    $block, $strings);
                foreach ($strings[1] as $str) {
                    $clean = preg_replace('/[^\x20-\x7E]/', '', $str);
                    if (strlen(trim($clean)) > 2) {
                        $text .= $clean . ' ';
                    }
                }
            }

            // Method 2: Decompress streams
            if (strlen(trim($text)) < 50) {
                preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s',
                    $content, $streams);
                foreach ($streams[1] as $stream) {
                    $decoded = @gzuncompress($stream);
                    if ($decoded === false) {
                        $decoded = @gzinflate($stream);
                    }
                    if ($decoded !== false) {
                        preg_match_all(
                            '/\(([^\)]{1,200})\)\s*Tj/s',
                            $decoded, $tj
                        );
                        foreach ($tj[1] as $t) {
                            $clean = preg_replace('/[^\x20-\x7E]/', '', $t);
                            if (strlen(trim($clean)) > 2) {
                                $text .= $clean . ' ';
                            }
                        }
                    }
                }
            }

            // Method 3: Extract readable words and numbers only
            if (strlen(trim($text)) < 50) {
                preg_match_all(
                    '/[A-Za-z][A-Za-z\s]{2,}|'
                    . '\$[\d,]+\.?\d*|'
                    . '\d{1,3}(,\d{3})*(\.\d+)?/',
                    $content,
                    $readable
                );
                $filtered = array_filter(
                    $readable[0],
                    function($s) {
                        return preg_match('/[A-Za-z]{2,}|\d{3,}/', $s)
                            && strlen(trim($s)) > 2
                            && !preg_match(
                                '/^(obj|endobj|stream|xref|startxref|'
                                . 'trailer|BT|ET|Tf|Td|Tm|cm|re|SCN|'
                                . 'scn|RG|rg|W|n|f|S|CS|cs|Do|BMC|'
                                . 'EMC|BDC|True|False)$/',
                                trim($s)
                            );
                    }
                );
                $text = implode(' ', array_slice($filtered, 0, 200));
            }

            // Clean and validate
            $clean_text  = preg_replace('/[^\x20-\x7E\n]/', '', $text);
            $clean_text  = preg_replace('/\s+/', ' ', $clean_text);
            $total_chars = strlen($text);
            $clean_chars = strlen($clean_text);
            $ratio       = $total_chars > 0
                         ? $clean_chars / $total_chars : 0;

            if ($ratio < 0.5 || strlen(trim($clean_text)) < 50) {
                return "SCANNED_PDF: This appears to be a scanned "
                     . "or image-based PDF. Please upload a JPG or PNG "
                     . "screenshot of the document for AI vision analysis, "
                     . "or use a CSV/TXT file for accurate results.";
            }

            $text = $clean_text;
            break;

        case 'CSV':
            $rows    = [];
            $headers = [];
            $count   = 0;
            $handle  = fopen($full_path, 'r');
            if ($handle) {
                while (($row = fgetcsv($handle)) !== false
                    && $count < 100) {
                    if ($count === 0) {
                        $headers = $row;
                    } else {
                        $combined = [];
                        foreach ($row as $i => $val) {
                            $key        = $headers[$i] ?? "Field$i";
                            $combined[] = trim($key) . ': ' . trim($val);
                        }
                        $rows[] = implode(' | ', $combined);
                    }
                    $count++;
                }
                fclose($handle);
            }
            $text = "CSV DATA:\nFields: "
                  . implode(', ', $headers) . "\n"
                  . implode("\n", $rows);
            break;

        case 'TXT':
            $text = file_get_contents($full_path);
            $text = preg_replace('/[^\x20-\x7E\n]/', '', $text);
            break;

        case 'JPG':
        case 'JPEG':
        case 'PNG':
            return 'IMAGE_FILE';

        case 'DOCX':
            $zip = new ZipArchive();
            if ($zip->open($full_path) === true) {
                $xml = $zip->getFromName('word/document.xml');
                $zip->close();
                if ($xml) {
                    $xml  = str_replace('</w:p>', "\n", $xml);
                    $xml  = str_replace('</w:r>',  ' ', $xml);
                    $text = strip_tags($xml);
                    $text = preg_replace('/[^\x20-\x7E\n]/', '', $text);
                    $text = preg_replace('/\s{2,}/', ' ', $text);
                }
            }
            break;

        case 'XLSX':
            $zip = new ZipArchive();
            if ($zip->open($full_path) === true) {
                $str_xml   = $zip->getFromName('xl/sharedStrings.xml');
                $sheet_xml = $zip->getFromName(
                    'xl/worksheets/sheet1.xml'
                );
                $zip->close();
                $strings = [];
                if ($str_xml) {
                    preg_match_all('/<t[^>]*>([^<]+)<\/t>/',
                        $str_xml, $sm);
                    $strings = $sm[1];
                }
                $values = [];
                if ($sheet_xml) {
                    preg_match_all('/<v>([^<]+)<\/v>/',
                        $sheet_xml, $vm);
                    $values = $vm[1];
                }
                $text  = "SPREADSHEET DATA:\n";
                $text .= "Labels: "
                       . implode(' | ', array_slice($strings, 0, 80))
                       . "\nValues: "
                       . implode(', ', array_slice($values, 0, 80));
            }
            break;

        default:
            $raw  = @file_get_contents($full_path);
            $text = preg_replace('/[^\x20-\x7E\n]/', ' ', $raw ?? '');
            $text = preg_replace('/\s{3,}/', ' ', $text);
            break;
    }

    $text = trim($text);

    if (empty($text) || strlen($text) < 20) {
        return "NO_TEXT: Could not extract readable text from this file.";
    }

    $text = preg_replace('/[^\x20-\x7E\n\t]/', '', $text);
    $text = preg_replace('/\s{3,}/', ' ', $text);

    return substr(trim($text), 0, 4000);
}

// ── Call Groq AI API ─────────────────────────────────
function analyzeWithGemini($extracted_text, $file_info, $is_image = false, $tax_rules_text = null) {
    $api_key   = 'YOUR_GROQ_API_KEY_HERE';
    $client    = $file_info['client_name'];
    $tax_year  = $file_info['tax_year'] ?? 'N/A';
    $notes     = $file_info['client_notes'] ?? '';
    $file_name = $file_info['file_name'];
    $file_path = __DIR__ . '/../' . $file_info['file_path'];

    $prompt = 'You are TaxStart AI, an expert international tax advisor.

CRITICAL INSTRUCTION: Read the document carefully and extract ALL
real numbers from it. Do NOT make up or estimate numbers. Only use
figures that actually appear in the document.

CLIENT INFORMATION:
- Client Name  : ' . $client . '
- Tax Year     : ' . $tax_year . '
- Advisor Notes: ' . $notes . '
- File Name    : ' . $file_name . '
' . ($tax_rules_text ? "\nOFFICIAL TAX RULES TO USE:\n" . $tax_rules_text . "\n" : '') . '
INSTRUCTIONS:
1. Read every number and label visible in the document
2. Extract ALL financial figures that actually appear
3. Identify the country and tax jurisdiction
4. Calculate taxes using ONLY real numbers from the document
5. If a number is NOT visible write "Not found in document"
6. Do NOT invent or estimate any figures

Please provide your analysis in this exact structure:

## COUNTRY AND TAX JURISDICTION DETECTED
State the exact country and tax authority from the document.
State which tax year rules you are applying.

## REQUIRED DOCUMENTS CHECKLIST
- PROVIDED: [documents visible in this upload]
- MISSING: [documents needed but not provided]
- RECOMMENDED: [optional but beneficial documents]

## EXTRACTED FINANCIAL DATA
List every single number found in the document:

Category | Amount | Box/Location in Document
[Every financial figure found with exact source]

## TAX CALCULATION BREAKDOWN
Using ONLY real numbers from the document:

Total Gross Income: [exact from document]
Total Deductions: [exact from document or 0]
Taxable Income: [calculated]

Tax Bracket Breakdown for [country] [year]:
[Each bracket with real income amounts]

Gross Tax Payable: [calculated]
Tax Credits Applied: [standard credits for country]
Net Tax Payable: [calculated]
Tax Already Withheld: [from document]
Final Refund or Owed: [calculated with exact amount]
Effective Tax Rate: [percentage]

## DEDUCTIONS AND CREDITS ANALYSIS
Deductions found in document:
[Each with exact amount]

Additional deductions client may be missing:
[With estimated savings]

Total Potential Savings: [amount]

## ISSUES AND FLAGS
- CRITICAL: [serious issues in document]
- WARNING: [items needing attention]
- INFO: [general observations]

## TAX ADVISORY AND RECOMMENDATIONS
1. [Specific recommendation with dollar impact]
2. [Specific recommendation with dollar impact]
3. [Specific recommendation with dollar impact]

## IMPORTANT DEADLINES
[All deadlines for detected country and tax year]

## NEXT STEPS FOR ADVISOR
1. [Action based on document findings]
2. [Action based on document findings]
3. [Action based on document findings]
4. [Action based on document findings]
5. [Action based on document findings]

## TAX SUMMARY SCORECARD
Category | Value | Source
Total Income | [real value] | [document location]
Total Deductions | [real value] | [document location]
Taxable Income | [calculated] | Calculated
Tax Payable | [calculated] | Calculated
Effective Rate | [percentage] | Calculated
Potential Savings | [estimated] | Advisory
Compliance Risk | [Low/Medium/High] | Assessment
Filing Status | [Ready/Pending] | Assessment';

    // ── BUILD MESSAGES BASED ON FILE TYPE ────────────
    $messages  = [];
    $file_type = strtoupper($file_info['file_type']);

    // ── IMAGE FILES — Vision AI ───────────────────────
    if (in_array($file_type, ['JPG', 'JPEG', 'PNG'])) {

        if (file_exists($file_path)) {
            $image_data = base64_encode(
                file_get_contents($file_path)
            );
            $mime_type = ($file_type === 'PNG')
                       ? 'image/png' : 'image/jpeg';

            $messages = [
                [
                    'role'    => 'system',
                    'content' => 'You are TaxStart AI, a professional '
                               . 'international tax advisor. Read the '
                               . 'document image carefully and extract '
                               . 'all visible numbers and text. Never '
                               . 'invent figures. Use plain text headings.'
                ],
                [
                    'role'    => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $prompt
                        ],
                        [
                            'type'      => 'image_url',
                            'image_url' => [
                                'url' => "data:$mime_type;base64,$image_data"
                            ]
                        ]
                    ]
                ]
            ];
            $model = 'meta-llama/llama-4-scout-17b-16e-instruct';

        } else {
            $messages = [
                [
                    'role'    => 'system',
                    'content' => 'You are TaxStart AI, a professional tax advisor.'
                ],
                [
                    'role'    => 'user',
                    'content' => $prompt . "\n\nNote: Image file could not be loaded."
                ]
            ];
            $model = 'llama-3.3-70b-versatile';
        }

    // ── PDF — Try ImageMagick, fallback to text ───────
    } elseif ($file_type === 'PDF') {

        $converted_image = null;

        if (extension_loaded('imagick')) {
            try {
                $imagick = new Imagick();
                $imagick->setResolution(150, 150);
                $imagick->readImage($file_path . '[0]');
                $imagick->setImageFormat('jpeg');
                $imagick->setImageCompressionQuality(85);
                $imagick->flattenImages();
                $converted_image = base64_encode(
                    $imagick->getImageBlob()
                );
                $imagick->clear();
                $imagick->destroy();
            } catch (Exception $e) {
                $converted_image = null;
            }
        }

        // Try extracting embedded images from PDF
        if (!$converted_image) {
            $pdf_content = file_get_contents($file_path);
            preg_match_all(
                '/\xFF\xD8\xFF.*?\xFF\xD9/s',
                $pdf_content,
                $jpeg_matches,
                PREG_OFFSET_CAPTURE
            );
            if (!empty($jpeg_matches[0])) {
                $largest = '';
                foreach ($jpeg_matches[0] as $match) {
                    if (strlen($match[0]) > strlen($largest)) {
                        $largest = $match[0];
                    }
                }
                if (strlen($largest) > 5000) {
                    $converted_image = base64_encode($largest);
                }
            }
        }

        if ($converted_image) {
            $messages = [
                [
                    'role'    => 'system',
                    'content' => 'You are TaxStart AI, a professional '
                               . 'international tax advisor. Read this '
                               . 'tax document image carefully and extract '
                               . 'all visible financial data. Never invent '
                               . 'numbers. Use plain text headings only.'
                ],
                [
                    'role'    => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $prompt
                        ],
                        [
                            'type'      => 'image_url',
                            'image_url' => [
                                'url' => "data:image/jpeg;base64,$converted_image"
                            ]
                        ]
                    ]
                ]
            ];
            $model = 'meta-llama/llama-4-scout-17b-16e-instruct';

        } else {
            // Use extracted text fallback
            $messages = [
                [
                    'role'    => 'system',
                    'content' => 'You are TaxStart AI, a professional '
                               . 'international tax advisor. Read the '
                               . 'document content carefully and extract '
                               . 'all financial data. Never invent numbers. '
                               . 'Use plain text headings only.'
                ],
                [
                    'role'    => 'user',
                    'content' => $prompt
                               . "\n\n===DOCUMENT CONTENT===\n"
                               . $extracted_text
                               . "\n===END OF DOCUMENT==="
                ]
            ];
            $model = 'llama-3.3-70b-versatile';
        }

    // ── CSV, TXT, DOCX, XLSX — Text ───────────────────
    } else {
        $messages = [
            [
                'role'    => 'system',
                'content' => 'You are TaxStart AI, a professional '
                           . 'international tax advisor. Read the '
                           . 'document content carefully and extract '
                           . 'all real financial numbers. Never invent '
                           . 'figures. Use plain text headings only.'
            ],
            [
                'role'    => 'user',
                'content' => $prompt
                           . "\n\n===DOCUMENT CONTENT START===\n"
                           . $extracted_text
                           . "\n===DOCUMENT CONTENT END==="
            ]
        ];
        $model = 'llama-3.3-70b-versatile';
    }

    // ── CALL GROQ API ─────────────────────────────────
    $payload = [
        'model'       => $model,
        'messages'    => $messages,
        'max_tokens'  => 1500,
        'temperature' => 0.1
    ];

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT    => 90,
    ]);

    $response  = curl_exec($ch);
    $err       = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        return "AI Error (curl): " . $err;
    }

    if (empty($response)) {
        return "AI Error: Empty response. HTTP Code: " . $http_code;
    }

    $data = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return "AI Error: Invalid JSON response received.";
    }

    if (isset($data['error'])) {
        return "API Error: " . $data['error']['message'];
    }

    if (isset($data['choices'][0]['message']['content'])
        && !empty($data['choices'][0]['message']['content'])) {
        return $data['choices'][0]['message']['content'];
    }

    return "AI Error: Unexpected response. HTTP: " . $http_code;
}

// ── RUN ANALYSIS ─────────────────────────────────────
$run_now = !$existing;

if ($run_now) {
    $file_type_upper = strtoupper($file['file_type']);
    $is_image        = in_array($file_type_upper,
        ['JPG', 'JPEG', 'PNG']);
    $is_pdf          = ($file_type_upper === 'PDF');

    if ($is_image) {
        $extracted_text = 'IMAGE_FILE';
    } elseif ($is_pdf) {
        $extracted_text = extractTextFromFile(
            $file['file_path'],
            $file['file_type']
        );
        if (strpos($extracted_text, 'SCANNED_PDF:') === 0
         || strpos($extracted_text, 'NO_TEXT:')     === 0
         || strlen(trim($extracted_text))            < 50) {
            $extracted_text = 'SCANNED_PDF';
        }
    } else {
        $extracted_text = extractTextFromFile(
            $file['file_path'],
            $file['file_type']
        );
    }

    $taxAI     = new TaxStartAI($pdo);
    $ai_advice = $taxAI->analyzeDocument($extracted_text, $file, $is_image, $tax_rules_text);
    $ai_advice = $ai_advice
              ?? 'Analysis could not be completed. Please try again.';

    $confidence = (strpos($ai_advice, 'API Error') !== false
               || strpos($ai_advice, 'AI Error')   !== false)
               ? 0.00 : 92.50;

    $ins = $pdo->prepare("
        INSERT INTO ai_analysis
            (file_id, client_id, advisor_id,
             extracted_text, ai_advice, confidence)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $ins->execute([
        $file_id,
        $file['client_id'],
        $advisor['id'],
        $extracted_text,
        $ai_advice,
        $confidence
    ]);

    $pdo->prepare("UPDATE uploaded_files SET status='analyzed' WHERE file_id=?")
        ->execute([$file_id]);

    logActivity($pdo, $advisor['id'], 'AI_ANALYSIS',
        "Analyzed file: {$file['file_name']}");

    $existing = $pdo->prepare("
        SELECT * FROM ai_analysis WHERE file_id = ?
    ");
    $existing->execute([$file_id]);
    $existing = $existing->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Analysis – TaxStart AI</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }

        body {
            font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background:#f0f2f7; display:flex; min-height:100vh;
        }

        .sidebar {
            width:260px;
            background:linear-gradient(180deg,#1a1a2e 0%,#0f3460 100%);
            color:white; display:flex; flex-direction:column;
            position:fixed; height:100vh; z-index:100;
        }

        .sidebar-brand {
            padding:28px 24px 20px;
            border-bottom:1px solid rgba(255,255,255,0.1);
        }

        .sidebar-brand h2 { font-size:20px; font-weight:700; }
        .sidebar-brand span {
            font-size:11px; opacity:0.6;
            letter-spacing:1px; text-transform:uppercase;
        }

        .sidebar-user {
            padding:20px 24px; display:flex;
            align-items:center; gap:12px;
            border-bottom:1px solid rgba(255,255,255,0.1);
        }

        .avatar {
            width:42px; height:42px;
            background:linear-gradient(135deg,#2ecc71,#1a73e8);
            border-radius:50%; display:flex;
            align-items:center; justify-content:center;
            font-size:18px; font-weight:700; color:white;
        }

        .sidebar-user .info small {
            display:block; font-size:11px; opacity:0.6;
            text-transform:uppercase; letter-spacing:0.5px;
        }

        .sidebar-user .info strong { font-size:14px; }
        .sidebar-nav { flex:1; padding:20px 0; }

        .nav-label {
            font-size:10px; text-transform:uppercase;
            letter-spacing:1.5px; opacity:0.45; padding:10px 24px 6px;
        }

        .nav-item {
            display:flex; align-items:center; gap:12px;
            padding:13px 24px; color:rgba(255,255,255,0.75);
            text-decoration:none; font-size:14px; font-weight:500;
            transition:all 0.2s; border-left:3px solid transparent;
        }

        .nav-item:hover, .nav-item.active {
            background:rgba(255,255,255,0.08);
            color:#fff; border-left-color:#2ecc71;
        }

        .nav-item .icon { font-size:18px; }

        .sidebar-footer {
            padding:20px 24px;
            border-top:1px solid rgba(255,255,255,0.1);
        }

        .btn-logout {
            display:block; width:100%; padding:11px;
            background:rgba(233,69,96,0.2);
            border:1px solid rgba(233,69,96,0.4);
            color:#e94560; border-radius:8px;
            text-align:center; text-decoration:none;
            font-size:14px; font-weight:600; transition:background 0.2s;
        }

        .btn-logout:hover { background:rgba(233,69,96,0.35); }

        .main {
            margin-left:260px; flex:1;
            display:flex; flex-direction:column;
        }

        .topbar {
            background:#fff; padding:18px 32px;
            display:flex; align-items:center;
            justify-content:space-between;
            box-shadow:0 2px 8px rgba(0,0,0,0.06);
            position:sticky; top:0; z-index:50;
        }

        .topbar h1 { font-size:20px; font-weight:700; color:#1a1a2e; }

        .page-body { padding:30px 32px; flex:1; }

        .file-info-bar {
            background:#fff; border-radius:14px;
            padding:20px 26px; margin-bottom:24px;
            box-shadow:0 2px 12px rgba(0,0,0,0.06);
            display:flex; align-items:center;
            justify-content:space-between; flex-wrap:wrap; gap:16px;
        }

        .file-meta { display:flex; align-items:center; gap:16px; }

        .file-icon {
            width:52px; height:52px; border-radius:12px;
            background:#e8f0fe; display:flex;
            align-items:center; justify-content:center; font-size:26px;
        }

        .file-details h3 { font-size:16px; font-weight:700; color:#1a1a2e; }
        .file-details p  { font-size:13px; color:#888; margin-top:3px; }

        .file-badges { display:flex; gap:10px; align-items:center; }

        .badge {
            display:inline-block; padding:5px 14px;
            border-radius:20px; font-size:12px; font-weight:600;
        }

        .badge-analyzed { background:#e6f9f0; color:#1e8449; }
        .badge-pending  { background:#fff3e0; color:#e67e22; }

        .confidence-bar {
            display:flex; align-items:center; gap:10px; font-size:13px;
        }

        .confidence-bar .bar {
            width:120px; height:8px; background:#f0f0f0;
            border-radius:4px; overflow:hidden;
        }

        .confidence-bar .fill {
            height:100%;
            background:linear-gradient(90deg,#2ecc71,#1a73e8);
            border-radius:4px;
        }

        .result-grid {
            display:grid; grid-template-columns:1fr 300px; gap:24px;
        }

        .card {
            background:#fff; border-radius:14px; padding:26px;
            box-shadow:0 2px 12px rgba(0,0,0,0.06);
        }

        .section-title {
            font-size:16px; font-weight:700;
            color:#1a1a2e; margin-bottom:16px;
        }

        .ai-content { font-size:14px; color:#333; line-height:1.8; }

        .ai-content h2 {
            font-size:15px; font-weight:700; color:#fff;
            background:linear-gradient(135deg,#0f3460,#1a73e8);
            padding:10px 16px; border-radius:8px; margin:20px 0 10px;
        }

        .ai-content h2:first-child { margin-top:0; }

        .ai-content h3 {
            font-size:14px; font-weight:700; color:#0f3460;
            margin:12px 0 6px; padding-left:10px;
            border-left:3px solid #1a73e8;
        }

        .ai-content ul { padding-left:20px; margin:8px 0; }
        .ai-content ul li { margin-bottom:5px; }
        .ai-content ol { padding-left:20px; margin:8px 0; }
        .ai-content ol li { margin-bottom:6px; }
        .ai-content strong { color:#1a1a2e; }

        .ai-content table {
            width:100%; border-collapse:collapse;
            margin:12px 0; font-size:13px;
        }

        .ai-content table td {
            padding:9px 13px; border:1px solid #e0e0e0;
        }

        .ai-content table tr:nth-child(even) td { background:#f8f9fa; }

        .ai-content table tr:first-child td {
            background:#e8f0fe; font-weight:700; color:#0f3460;
        }

        .info-item {
            display:flex; flex-direction:column; gap:4px;
            padding:12px 0; border-bottom:1px solid #f5f5f5;
        }

        .info-item:last-child { border-bottom:none; }

        .info-item label {
            font-size:11px; font-weight:700; color:#aaa;
            text-transform:uppercase; letter-spacing:0.5px;
        }

        .info-item span { font-size:14px; font-weight:600; color:#1a1a2e; }

        .btn-generate {
            display:block; width:100%; padding:13px;
            background:linear-gradient(135deg,#7b2d8b,#e94560);
            color:#fff; border:none; border-radius:10px;
            font-size:14px; font-weight:700; cursor:pointer;
            text-decoration:none; text-align:center;
            transition:opacity 0.2s; margin-top:14px;
        }

        .btn-generate:hover { opacity:0.9; }

        .btn-reanalyze {
            display:block; width:100%; padding:11px;
            background:#f0f0f0; color:#555; border:none;
            border-radius:10px; font-size:13px; font-weight:600;
            cursor:pointer; text-decoration:none; text-align:center;
            transition:background 0.2s; margin-top:10px;
        }

        .btn-reanalyze:hover { background:#e0e0e0; }

        .extracted-card { margin-top:20px; }

        .extracted-text {
            background:#f8f9fa; border-radius:8px; padding:14px;
            font-size:12px; color:#666; font-family:monospace;
            line-height:1.6; max-height:200px; overflow-y:auto;
            white-space:pre-wrap; word-break:break-word;
        }

        .extraction-status {
            background:#fff8e1; border-left:4px solid #f5a623;
            border-radius:8px; padding:12px 16px;
            font-size:13px; color:#8a6500;
            margin-bottom:16px; line-height:1.6;
        }

        .extraction-status.good {
            background:#f0fff4; border-left-color:#2ecc71; color:#1e8449;
        }

        .extraction-status.bad {
            background:#fff0f0; border-left-color:#e94560; color:#c0392b;
        }

        .loading-card {
            background:#fff; border-radius:14px; padding:60px 40px;
            text-align:center; box-shadow:0 2px 12px rgba(0,0,0,0.06);
        }

        .spinner {
            width:60px; height:60px; border:4px solid #f0f0f0;
            border-top-color:#1a73e8; border-radius:50%;
            animation:spin 0.9s linear infinite; margin:0 auto 20px;
        }

        @keyframes spin { to { transform:rotate(360deg); } }

        .loading-card h3 {
            font-size:18px; font-weight:700; color:#1a1a2e; margin-bottom:8px;
        }

        .loading-card p { font-size:14px; color:#888; }
    </style>
</head>
<body>

<aside class="sidebar">
    <div class="sidebar-brand">
        <h2>TaxStart AI</h2>
        <span>ABC Tech Ltd.</span>
    </div>

    <div class="sidebar-user">
        <div class="avatar">
            <?= strtoupper(substr($advisor['name'], 0, 1)) ?>
        </div>
        <div class="info">
            <small>Tax Advisor</small>
            <strong><?= sanitize($advisor['name']) ?></strong>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-label">Main</div>
        <a href="../advisor/dashboard.php" class="nav-item">
            <span class="icon">📊</span> Dashboard
        </a>
        <a href="../advisor/clients.php" class="nav-item">
            <span class="icon">🧑‍💼</span> My Clients
        </a>
        <a href="../advisor/upload.php" class="nav-item active">
            <span class="icon">📁</span> Upload Files
        </a>
        <a href="../advisor/reports.php" class="nav-item">
            <span class="icon">📄</span> Reports
        </a>
        <a href="../advisor/compare.php" class="nav-item">
            <span class="icon">⚖️</span> Compare Scenarios
        </a>
        <a href="../advisor/calculator.php" class="nav-item">
            <span class="icon">🧮</span> Tax Calculator
        </a>
        <a href="../advisor/subscription.php" class="nav-item">
            <span class="icon">💳</span> Subscription
        </a>
    </nav>

    <div class="sidebar-footer">
        <a href="http://localhost/taxstart/logout.php"
           class="btn-logout">Logout</a>
    </div>
</aside>

<div class="main">

    <div class="topbar">
        <h1>AI Tax Analysis</h1>
        <a href="../advisor/upload.php"
           style="font-size:14px; color:#1a73e8;
                  text-decoration:none; font-weight:600;">
            Back to Uploads
        </a>
    </div>

    <div class="page-body">

        <div class="file-info-bar">
            <div class="file-meta">
                <div class="file-icon">📄</div>
                <div class="file-details">
                    <h3><?= sanitize($file['file_name']) ?></h3>
                    <p>
                        Client: <strong>
                            <?= sanitize($file['client_name']) ?>
                        </strong>
                        &nbsp;·&nbsp;
                        Tax Year: <strong>
                            <?= $file['tax_year'] ?: 'N/A' ?>
                        </strong>
                        &nbsp;·&nbsp;
                        <?= $file['file_size'] ?> KB
                        &nbsp;·&nbsp;
                        Type: <strong>
                            <?= sanitize($file['file_type']) ?>
                        </strong>
                    </p>
                </div>
            </div>
            <div class="file-badges">
                <span class="badge badge-<?= $file['status'] ?>">
                    <?= ucfirst($file['status']) ?>
                </span>
                <?php if ($existing): ?>
                <div class="confidence-bar">
                    <span>Confidence</span>
                    <div class="bar">
                        <div class="fill"
                             style="width:<?= $existing['confidence'] ?>%">
                        </div>
                    </div>
                    <strong><?= $existing['confidence'] ?>%</strong>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($existing): ?>
        <div class="result-grid">

            <div>
                <?php
                $ext_text = $existing['extracted_text'] ?? '';
                $ext_len  = strlen($ext_text);
                if (in_array($ext_text, ['IMAGE_FILE', '[IMAGE]'])): ?>
                    <div class="extraction-status good">
                        Image file sent directly to Vision AI —
                        AI reads the document visually like a human.
                    </div>
                <?php elseif ($ext_text === 'SCANNED_PDF'): ?>
                    <div class="extraction-status good">
                        Scanned PDF detected — sent to Vision AI
                        for visual reading.
                    </div>
                <?php elseif ($ext_len < 100): ?>
                    <div class="extraction-status bad">
                        Warning: Very little text extracted
                        (<?= $ext_len ?> characters).
                        Try uploading a JPG screenshot or CSV file
                        for better results.
                    </div>
                <?php else: ?>
                    <div class="extraction-status good">
                        Document successfully read:
                        <?= number_format($ext_len) ?> characters
                        extracted and sent to AI.
                    </div>
                <?php endif; ?>

                <div class="card">
                    <div class="section-title">
                        AI Tax Analysis and Advice
                    </div>
                    <div class="ai-content">
                        <?php
                        $advice = htmlspecialchars(
                            $existing['ai_advice']
                            ?? 'No analysis available. Please re-analyze.',
                            ENT_QUOTES, 'UTF-8'
                        );
                        $advice = preg_replace('/^## (.+)$/m',
                            '<h2>$1</h2>', $advice);
                        $advice = preg_replace('/^### (.+)$/m',
                            '<h3>$1</h3>', $advice);
                        $advice = preg_replace('/\*\*(.+?)\*\*/',
                            '<strong>$1</strong>', $advice);
                        $advice = preg_replace('/^- (.+)$/m',
                            '<li>$1</li>', $advice);
                        $advice = preg_replace('/^(\d+)\. (.+)$/m',
                            '<li><strong>$1.</strong> $2</li>', $advice);
                        $advice = preg_replace_callback(
                            '/(\|.+\|\n)+/m',
                            function($matches) {
                                $rows = explode("\n",
                                    trim($matches[0]));
                                $html = '<table>';
                                foreach ($rows as $i => $row) {
                                    if (strpos($row, '---') !== false)
                                        continue;
                                    $cells = array_map('trim',
                                        explode('|', trim($row, '|')));
                                    $tag   = $i === 0 ? 'th' : 'td';
                                    $html .= '<tr>';
                                    foreach ($cells as $cell) {
                                        $html .= "<$tag>$cell</$tag>";
                                    }
                                    $html .= '</tr>';
                                }
                                $html .= '</table>';
                                return $html;
                            },
                            $advice
                        );
                        $advice = nl2br($advice);
                        echo $advice;
                        ?>
                    </div>
                </div>

                <?php if ($ext_text
                    && !in_array($ext_text,
                        ['IMAGE_FILE','[IMAGE]','SCANNED_PDF'])): ?>
                <div class="card extracted-card">
                    <div class="section-title">
                        Extracted Document Text
                        <small style="font-size:12px;color:#aaa;
                               font-weight:400;">
                            (<?= number_format($ext_len) ?> chars
                            fed to AI)
                        </small>
                    </div>
                    <div class="extracted-text">
                        <?= htmlspecialchars(
                            substr($ext_text, 0, 2000),
                            ENT_QUOTES, 'UTF-8'
                        ) ?>
                        <?= $ext_len > 2000 ? "\n...[truncated]" : '' ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- SIDE PANEL -->
            <div>
                <div class="card">
                    <div class="section-title">Analysis Details</div>

                    <div class="info-item">
                        <label>Client</label>
                        <span><?= sanitize($file['client_name']) ?></span>
                    </div>
                    <div class="info-item">
                        <label>Tax Year</label>
                        <span><?= $file['tax_year'] ?: 'N/A' ?></span>
                    </div>
                    <div class="info-item">
                        <label>File Name</label>
                        <span><?= sanitize($file['file_name']) ?></span>
                    </div>
                    <div class="info-item">
                        <label>File Type</label>
                        <span><?= sanitize($file['file_type']) ?></span>
                    </div>
                    <div class="info-item">
                        <label>Reading Method</label>
                        <span>
                            <?php
                            $ft = strtoupper($file['file_type']);
                            if (in_array($ft, ['JPG','JPEG','PNG'])) {
                                echo 'Vision AI';
                            } elseif ($ft === 'PDF') {
                                echo 'Vision AI / Text';
                            } else {
                                echo 'Text Extraction';
                            }
                            ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <label>Analyzed At</label>
                        <span>
                            <?= formatDate($existing['analyzed_at']) ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <label>AI Confidence</label>
                        <span><?= $existing['confidence'] ?>%</span>
                    </div>

                    <?php
                    // ── TIER MISMATCH CHECK ──────────────────
                    $client_tax_type = $file['tax_type'] ?? 'domestic';
                    $advice_text = strtolower($existing['ai_advice'] ?? '');
                    $intl_countries = ['united states', 'usa', 'irs', 'w-2', 'w2',
                        'united kingdom', 'uk', 'hmrc', 'p60',
                        'india', 'form 16', 'form16',
                        'australia', 'ato'];
                    $detected_intl = false;
                    foreach ($intl_countries as $kw) {
                        if (strpos($advice_text, $kw) !== false) {
                            $detected_intl = true;
                            break;
                        }
                    }

                    if ($client_tax_type === 'domestic' && $detected_intl): ?>
                        <div style="background:#fff3e0;border:2px solid #f5a623;
                            border-radius:10px;padding:14px 18px;margin-bottom:12px;">
                            <strong style="color:#e67e22;">⚠️ International Document Detected</strong>
                            <p style="font-size:13px;color:#8a6500;margin-top:6px;">
                                This client is on a <strong>Silver (Canada)</strong> tier,
                                but this document appears to be international.
                                Upgrade this client to <strong>Gold (International)</strong>
                                to generate reports for non-Canadian documents.
                            </p>
                            <a href="../advisor/subscription.php"
                               style="display:inline-block;margin-top:8px;padding:8px 18px;
                               background:#f5a623;color:white;border-radius:8px;
                               font-weight:700;font-size:13px;text-decoration:none;">
                                Upgrade to Gold →
                            </a>
                        </div>
                    <?php else: ?>
                        <a href="../advisor/reports.php?generate=<?= $existing['analysis_id'] ?>"
                           class="btn-generate">
                            Generate Full Report
                        </a>
                    <?php endif; ?>

                    <a href="analyze.php?file_id=<?= $file_id ?>&force=1"
                       class="btn-reanalyze"
                       onclick="return confirm(
                           'Re-run AI analysis on this file?')">
                        Re-Analyze Document
                    </a>
                </div>

                <div class="card" style="margin-top:16px;
                     background:#f8f9fa; border:2px dashed #e0e0e0;">
                    <div style="font-size:13px;color:#555;line-height:1.8;">
                        <strong style="color:#0f3460;">
                            Supported File Types:
                        </strong><br>
                        JPG / PNG — Vision AI reads directly<br>
                        CSV / TXT — Best for accuracy<br>
                        PDF — Text or Vision AI<br>
                        DOCX / XLSX — Text extraction<br><br>
                        <strong style="color:#e94560;">
                            Tip for T4:
                        </strong><br>
                        Take a screenshot of your T4 and
                        upload as PNG for best results.
                    </div>
                </div>
            </div>

        </div>

        <?php else: ?>
        <div class="loading-card">
            <div class="spinner"></div>
            <h3>AI is analyzing your document...</h3>
            <p>
                TaxStart AI is reading
                <strong><?= sanitize($file['file_name']) ?></strong>
                for <strong><?= sanitize($file['client_name']) ?></strong>.
            </p>
        </div>
        <?php endif; ?>

    </div>
</div>

</body>
</html>