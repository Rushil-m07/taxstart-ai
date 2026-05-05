<?php
// ============================================================
// TaxStart AI — Neuro-Symbolic AI Engine
// TaxStart AI — Neuro-Symbolic Tax Analysis Engine
//
// ARCHITECTURE:
//   SYMBOLIC LAYER  — Hard rules, tax logic, validation
//   NEURAL LAYER    — Groq API (llama-3.3-70b / llama-4-scout)
//   ORCHESTRATOR    — Sends commands, validates responses,
//                     retries on failure, scores confidence
// ============================================================

class TaxStartAI {

    // ── CONFIGURATION ────────────────────────────────────
    private string $api_key  = 'YOUR_GROQ_API_KEY_HERE';
    private string $text_model   = 'llama-3.3-70b-versatile';
    private string $vision_model = 'meta-llama/llama-4-scout-17b-16e-instruct';
    private string $api_url      = 'https://api.groq.com/openai/v1/chat/completions';
    private int    $max_retries  = 3;
    private float  $temperature  = 0.1;

    // ── INTERNAL STATE ────────────────────────────────────
    private array  $execution_log  = [];   // Full trace of what happened
    private array  $rule_checks    = [];   // Which rules passed/failed
    private int    $retry_count    = 0;    // How many retries were needed
    private float  $confidence     = 0.0;  // Final confidence score 0-100
    private string $last_raw       = '';   // Raw response from Groq
    private ?object $pdo;

    // ── SYMBOLIC KNOWLEDGE BASE ───────────────────────────
    // These are the hard-coded symbolic rules the system knows
    // regardless of what the neural layer returns

    private array $tax_brackets = [
        'Canada' => [
            2024 => [
                ['min'=>0,       'max'=>55867,   'rate'=>15.0,  'label'=>'First bracket'],
                ['min'=>55867,   'max'=>111733,  'rate'=>20.5,  'label'=>'Second bracket'],
                ['min'=>111733,  'max'=>154906,  'rate'=>26.0,  'label'=>'Third bracket'],
                ['min'=>154906,  'max'=>220000,  'rate'=>29.0,  'label'=>'Fourth bracket'],
                ['min'=>220000,  'max'=>PHP_INT_MAX, 'rate'=>33.0, 'label'=>'Top bracket'],
            ]
        ],
        'United States' => [
            2024 => [
                ['min'=>0,       'max'=>11600,   'rate'=>10.0,  'label'=>'10% bracket'],
                ['min'=>11600,   'max'=>47150,   'rate'=>12.0,  'label'=>'12% bracket'],
                ['min'=>47150,   'max'=>100525,  'rate'=>22.0,  'label'=>'22% bracket'],
                ['min'=>100525,  'max'=>191950,  'rate'=>24.0,  'label'=>'24% bracket'],
                ['min'=>191950,  'max'=>243725,  'rate'=>32.0,  'label'=>'32% bracket'],
                ['min'=>243725,  'max'=>609350,  'rate'=>35.0,  'label'=>'35% bracket'],
                ['min'=>609350,  'max'=>PHP_INT_MAX, 'rate'=>37.0, 'label'=>'Top bracket'],
            ]
        ],
        'United Kingdom' => [
            2024 => [
                ['min'=>0,       'max'=>12570,   'rate'=>0.0,   'label'=>'Personal allowance'],
                ['min'=>12570,   'max'=>50270,   'rate'=>20.0,  'label'=>'Basic rate'],
                ['min'=>50270,   'max'=>125140,  'rate'=>40.0,  'label'=>'Higher rate'],
                ['min'=>125140,  'max'=>PHP_INT_MAX, 'rate'=>45.0, 'label'=>'Additional rate'],
            ]
        ],
    ];

    private array $deadlines = [
        'Canada'         => 'April 30, 2025',
        'United States'  => 'April 15, 2025',
        'United Kingdom' => 'January 31, 2025',
        'Australia'      => 'October 31, 2025',
        'India'          => 'July 31, 2025',
    ];

    private array $standard_deductions = [
        'Canada'         => ['Basic Personal Amount' => 15705],
        'United States'  => ['Standard Deduction (Single)' => 14600, 'Standard Deduction (Married)' => 29200],
        'United Kingdom' => ['Personal Allowance' => 12570],
        'Australia'      => ['Tax-Free Threshold' => 18200],
        'India'          => ['Standard Deduction' => 75000],
    ];

    // ── SYMBOLIC VALIDATION RULES ─────────────────────────
    // Each rule returns ['pass'=>bool, 'reason'=>string, 'severity'=>'critical|warning|info']

    private array $validation_rules = [
        'gross_income_positive',
        'tax_not_exceed_income',
        'effective_rate_realistic',
        'taxable_income_logical',
        'refund_mathematically_correct',
        'bracket_rates_realistic',
        'status_matches_refund',
        'deductions_not_exceed_income',
    ];

    // ─────────────────────────────────────────────────────
    public function __construct(?object $pdo = null) {
        $this->pdo = $pdo;
    }

    // ╔══════════════════════════════════════════════════╗
    // ║         PUBLIC INTERFACE METHODS                 ║
    // ╚══════════════════════════════════════════════════╝

    // ── ANALYZE A TAX DOCUMENT ───────────────────────────
    public function analyzeDocument(
        string $extracted_text,
        array  $file_info,
        bool   $is_image        = false,
        ?string $tax_rules_text = null
    ): string {
        $this->log('START', 'analyzeDocument', [
            'file'     => $file_info['file_name'] ?? 'unknown',
            'is_image' => $is_image
        ]);

        // Build structured command for neural layer
        $command = $this->buildAnalysisCommand(
            $extracted_text, $file_info, $tax_rules_text
        );

        // Choose model
        $model    = $is_image ? $this->vision_model : $this->text_model;
        $messages = $this->buildAnalysisMessages(
            $command, $extracted_text, $file_info, $is_image
        );

        // Call neural layer with retry
        $raw = $this->callNeuralLayer($messages, $model, 1500);

        // Validate free-text response (less strict for analysis)
        $this->validateAnalysisResponse($raw);

        $this->log('DONE', 'analyzeDocument',
            ['confidence' => $this->confidence]);

        return $raw;
    }

    // ── CALCULATE TAX ─────────────────────────────────────
    public function calculateTax(array $inputs): array {
        $this->log('START', 'calculateTax', [
            'country' => $inputs['country'],
            'income'  => $inputs['employment'] ?? 0
        ]);

        $attempts = 0;
        $result   = [];

        do {
            $attempts++;
            $this->retry_count = $attempts - 1;

            // Build command with symbolic pre-calculation hints
            $command  = $this->buildCalculationCommand($inputs, $attempts);
            $messages = [
                ['role' => 'system', 'content' =>
                    'You are a precise tax calculation engine for TaxStartAI. '
                    . 'Return ONLY key:value pairs. No markdown. No explanations.'],
                ['role' => 'user', 'content' => $command]
            ];

            $raw    = $this->callNeuralLayer($messages, $this->text_model, 500);
            $result = $this->parseKeyValue($raw);

            // Symbolic validation
            $checks = $this->validateCalculation($result, $inputs);
            $passed = array_filter($checks, fn($c) => !$c['pass'] && $c['severity'] === 'critical');

            if (empty($passed)) break;

            // Log retry reason
            $reasons = array_map(fn($c) => $c['reason'], $passed);
            $this->log('RETRY', 'calculateTax',
                ['attempt' => $attempts, 'reasons' => $reasons]);

            // Add correction hint for next attempt
            $inputs['_correction_hint'] = implode('. ', $reasons);

        } while ($attempts < $this->max_retries);

        // Final symbolic corrections
        $result = $this->applySymbolicCorrections($result, $inputs);

        // Score confidence
        $this->confidence = $this->scoreCalculationConfidence($result, $inputs);

        // Add metadata
        $result['_confidence']   = $this->confidence;
        $result['_retries']      = $this->retry_count;
        $result['_rule_checks']  = $this->rule_checks;
        $result['country']       = $inputs['country'];
        $result['tax_year']      = $inputs['tax_year']      ?? date('Y');
        $result['filing_status'] = $inputs['filing_status'] ?? 'Single';

        $this->log('DONE', 'calculateTax',
            ['confidence' => $this->confidence, 'retries' => $this->retry_count]);

        return $result;
    }

    // ── COMPARE TWO SCENARIOS ─────────────────────────────
    public function compareScenarios(
        array  $scenario_a,
        array  $scenario_b,
        string $country,
        ?string $tax_rules_text = null
    ): array {
        $this->log('START', 'compareScenarios', ['country' => $country]);

        $command  = $this->buildComparisonCommand(
            $scenario_a, $scenario_b, $country, $tax_rules_text
        );
        $messages = [
            ['role' => 'system', 'content' =>
                'You are a precise tax comparison engine for TaxStartAI. '
                . 'Return ONLY key:value pairs for both scenarios. '
                . 'No markdown. No explanations.'],
            ['role' => 'user', 'content' => $command]
        ];

        $raw    = $this->callNeuralLayer($messages, $this->text_model, 800);
        $result = $this->parseKeyValue($raw);

        // Validate both scenarios
        $a_valid = $this->validateCalculation(
            $this->extractScenario($result, 'A'),
            array_merge($scenario_a, ['country' => $country])
        );
        $b_valid = $this->validateCalculation(
            $this->extractScenario($result, 'B'),
            array_merge($scenario_b, ['country' => $country])
        );

        // Symbolic winner check
        $result = $this->validateWinner($result);

        $this->confidence            = $this->scoreComparisonConfidence(
            $result, $a_valid, $b_valid
        );
        $result['_confidence']       = $this->confidence;
        $result['_rule_checks_a']    = $a_valid;
        $result['_rule_checks_b']    = $b_valid;
        $result['inputs_a']          = $scenario_a;
        $result['inputs_b']          = $scenario_b;
        $result['country']           = $country;

        $this->log('DONE', 'compareScenarios',
            ['confidence' => $this->confidence]);

        return $result;
    }

    // ── GENERATE REPORT DATA ──────────────────────────────
    public function generateReport(array $analysis): array {
        $this->log('START', 'generateReport',
            ['client' => $analysis['client_name'] ?? 'N/A']);

        $command  = $this->buildReportCommand($analysis);
        $messages = [
            ['role' => 'system', 'content' =>
                'You are a tax report data extractor for TaxStartAI. '
                . 'Return ONLY structured key:value pairs. '
                . 'No markdown, no explanations.'],
            ['role' => 'user', 'content' => $command]
        ];

        $raw    = $this->callNeuralLayer($messages, $this->text_model, 600);
        $result = $this->parseKeyValue($raw);

        // Symbolic validation and enrichment
        $result = $this->enrichReportData($result, $analysis);

        // Check for missing critical fields
        $result = $this->fillMissingReportFields($result);

        $this->confidence         = $this->scoreReportConfidence($result);
        $result['_confidence']    = $this->confidence;
        $result['_engine']        = 'TaxStartAI v1.0 — Neuro-Symbolic';

        $this->log('DONE', 'generateReport',
            ['confidence' => $this->confidence]);

        return $result;
    }

    // ── GETTERS ───────────────────────────────────────────
    public function getLog(): array       { return $this->execution_log; }
    public function getConfidence(): float { return $this->confidence; }
    public function getRetries(): int      { return $this->retry_count; }
    public function getRuleChecks(): array { return $this->rule_checks; }

    // ╔══════════════════════════════════════════════════╗
    // ║        SYMBOLIC LAYER — RULES ENGINE             ║
    // ╚══════════════════════════════════════════════════╝

    private function validateCalculation(array $r, array $inputs): array {
        $checks      = [];
        $gross       = $this->num($r['GROSS_INCOME']     ?? 0);
        $taxable     = $this->num($r['TAXABLE_INCOME']   ?? 0);
        $gross_tax   = $this->num($r['GROSS_TAX']        ?? 0);
        $net_tax     = $this->num($r['NET_TAX']          ?? 0);
        $deductions  = $this->num($r['TOTAL_DEDUCTIONS'] ?? 0);
        $withheld    = $this->num($r['TAX_WITHHELD']     ?? 0);
        $refund      = $this->num($r['REFUND_OR_OWED']   ?? 0);
        $eff_rate    = $this->num($r['EFFECTIVE_RATE']   ?? 0);
        $status      = strtoupper(trim($r['STATUS']      ?? ''));

        // ── RULE 1: Gross income must be positive ─────────
        $calc_gross = ($inputs['employment']      ?? 0)
                    + ($inputs['self_employment'] ?? 0)
                    + ($inputs['rental']          ?? 0)
                    + ($inputs['investment']      ?? 0)
                    + ($inputs['other_income']    ?? 0);

        $checks[] = [
            'rule'     => 'gross_income_positive',
            'pass'     => $gross > 0,
            'severity' => 'critical',
            'reason'   => 'Gross income must be greater than zero',
            'expected' => $calc_gross,
            'got'      => $gross,
        ];

        // ── RULE 2: Tax cannot exceed income ──────────────
        $checks[] = [
            'rule'     => 'tax_not_exceed_income',
            'pass'     => $gross_tax <= $gross || $gross === 0.0,
            'severity' => 'critical',
            'reason'   => "Gross tax ($gross_tax) cannot exceed gross income ($gross)",
            'expected' => "<= $gross",
            'got'      => $gross_tax,
        ];

        // ── RULE 3: Effective rate must be 0-70% ──────────
        $checks[] = [
            'rule'     => 'effective_rate_realistic',
            'pass'     => $eff_rate >= 0 && $eff_rate <= 70,
            'severity' => 'critical',
            'reason'   => "Effective rate ($eff_rate%) must be between 0% and 70%",
            'expected' => '0-70',
            'got'      => $eff_rate,
        ];

        // ── RULE 4: Taxable income = gross - deductions ───
        if ($gross > 0 && $deductions >= 0) {
            $expected_taxable = max(0, $gross - $deductions);
            $tolerance        = $expected_taxable * 0.05; // 5% tolerance
            $diff             = abs($taxable - $expected_taxable);
            $checks[] = [
                'rule'     => 'taxable_income_logical',
                'pass'     => $diff <= max($tolerance, 500),
                'severity' => 'warning',
                'reason'   => "Taxable income should be ~$"
                            . number_format($expected_taxable, 2)
                            . " (gross minus deductions)",
                'expected' => $expected_taxable,
                'got'      => $taxable,
            ];
        }

        // ── RULE 5: Deductions cannot exceed gross income ─
        $checks[] = [
            'rule'     => 'deductions_not_exceed_income',
            'pass'     => $deductions <= $gross || $gross === 0.0,
            'severity' => 'warning',
            'reason'   => "Total deductions ($deductions) cannot exceed gross income ($gross)",
            'expected' => "<= $gross",
            'got'      => $deductions,
        ];

        // ── RULE 6: STATUS must match REFUND_OR_OWED sign ─
        $correct_status = $refund >= 0 ? 'REFUND' : 'OWED';
        $checks[] = [
            'rule'     => 'status_matches_refund',
            'pass'     => empty($status) || $status === $correct_status
                       || ($refund == 0 && $status === 'BALANCED'),
            'severity' => 'warning',
            'reason'   => "Status should be $correct_status when refund is $refund",
            'expected' => $correct_status,
            'got'      => $status,
        ];

        // ── RULE 7: Bracket rates must be realistic ───────
        for ($i = 1; $i <= 3; $i++) {
            $rate = $this->num($r["BRACKET_{$i}_RATE"] ?? -1);
            if ($rate >= 0) {
                $checks[] = [
                    'rule'     => "bracket_{$i}_rate_realistic",
                    'pass'     => $rate >= 0 && $rate <= 60,
                    'severity' => 'warning',
                    'reason'   => "Bracket $i rate ($rate%) seems unrealistic",
                    'expected' => '0-60',
                    'got'      => $rate,
                ];
            }
        }

        // ── SYMBOLIC TAX VERIFICATION (if we know brackets)
        $country = $inputs['country'] ?? '';
        $year    = (int)($inputs['tax_year'] ?? date('Y'));

        if (isset($this->tax_brackets[$country][$year]) && $taxable > 0) {
            $symbolic_tax   = $this->calculateSymbolicTax($taxable, $country, $year);
            $neural_tax     = $gross_tax;
            $tolerance      = $symbolic_tax * 0.10; // 10% tolerance
            $checks[] = [
                'rule'     => 'symbolic_tax_cross_check',
                'pass'     => abs($neural_tax - $symbolic_tax) <= max($tolerance, 200),
                'severity' => 'info',
                'reason'   => "Expected ~$"
                            . number_format($symbolic_tax, 2)
                            . " based on symbolic brackets, got $"
                            . number_format($neural_tax, 2),
                'expected' => $symbolic_tax,
                'got'      => $neural_tax,
                'symbolic_calculation' => true,
            ];
        }

        $this->rule_checks = $checks;
        return $checks;
    }

    private function calculateSymbolicTax(
        float  $taxable_income,
        string $country,
        int    $year
    ): float {
        $brackets = $this->tax_brackets[$country][$year] ?? [];
        $tax      = 0.0;

        foreach ($brackets as $bracket) {
            if ($taxable_income <= 0) break;
            $in_bracket  = min($taxable_income,
                $bracket['max'] - $bracket['min']);
            $in_bracket  = max(0, $in_bracket);
            $tax        += $in_bracket * ($bracket['rate'] / 100);
            $taxable_income -= $in_bracket;
        }

        return round($tax, 2);
    }

    private function applySymbolicCorrections(array $result, array $inputs): array {
        $gross    = $this->num($result['GROSS_INCOME']     ?? 0);
        $deduct   = $this->num($result['TOTAL_DEDUCTIONS'] ?? 0);
        $net_tax  = $this->num($result['NET_TAX']          ?? 0);
        $withheld = $this->num($result['TAX_WITHHELD']     ?? 0);
        $refund   = $this->num($result['REFUND_OR_OWED']   ?? 0);

        // Force correct STATUS based on REFUND_OR_OWED
        if ($refund > 0) {
            $result['STATUS'] = 'REFUND';
        } elseif ($refund < 0) {
            $result['STATUS'] = 'OWED';
        } else {
            $result['STATUS'] = 'BALANCED';
        }

        // Force correct EFFECTIVE_RATE if gross > 0
        if ($gross > 0 && $net_tax >= 0) {
            $calc_rate              = round(($net_tax / $gross) * 100, 2);
            $result['EFFECTIVE_RATE'] = $calc_rate;
        }

        return $result;
    }

    private function validateWinner(array $result): array {
        $a_tax  = $this->num(
            $result['SCENARIO_A_NET_TAX']        ?? 0
        );
        $b_tax  = $this->num(
            $result['SCENARIO_B_NET_TAX']        ?? 0
        );
        $better = $result['BETTER_SCENARIO']     ?? 'A';
        $diff   = $this->num($result['DIFFERENCE'] ?? 0);

        // Symbolic check: winner should have lower net tax
        $symbolic_winner = ($a_tax <= $b_tax) ? 'A' : 'B';
        if ($better !== $symbolic_winner) {
            $this->log('SYMBOLIC_CORRECTION', 'validateWinner', [
                'neural_winner'   => $better,
                'symbolic_winner' => $symbolic_winner,
                'a_tax'           => $a_tax,
                'b_tax'           => $b_tax,
            ]);
            $result['BETTER_SCENARIO'] = $symbolic_winner;
        }

        // Always correct the difference
        $result['DIFFERENCE'] = abs($a_tax - $b_tax);

        return $result;
    }

    private function validateAnalysisResponse(string $raw): void {
        $length = strlen($raw);
        $checks = [];

        $checks[] = [
            'rule' => 'response_not_empty',
            'pass' => $length > 50,
            'severity' => 'critical',
            'reason' => 'AI response is too short or empty',
        ];
        $checks[] = [
            'rule' => 'contains_country',
            'pass' => preg_match('/canada|usa|united states|uk|india|australia/i', $raw) === 1,
            'severity' => 'info',
            'reason' => 'Response does not mention a country',
        ];
        $checks[] = [
            'rule' => 'contains_numbers',
            'pass' => preg_match('/\$[\d,]+|\d+[\.,]\d{2}/', $raw) === 1,
            'severity' => 'warning',
            'reason' => 'Response does not contain any financial figures',
        ];
        $checks[] = [
            'rule' => 'no_error_message',
            'pass' => !preg_match('/error|failed|cannot|unable/i', $raw),
            'severity' => 'critical',
            'reason' => 'Response contains error language',
        ];

        $this->rule_checks = $checks;

        // Score confidence based on checks
        $passed       = array_filter($checks, fn($c) => $c['pass']);
        $this->confidence = (count($passed) / max(count($checks), 1)) * 92.5;
    }

    private function enrichReportData(array $result, array $analysis): array {
        $country = $result['COUNTRY'] ?? '';

        // Add filing deadline from symbolic knowledge base
        if (empty($result['FILING_DEADLINE'])
            || $result['FILING_DEADLINE'] === 'N/A') {
            foreach ($this->deadlines as $c => $date) {
                if (stripos($country, $c) !== false
                    || stripos($c, $country) !== false) {
                    $result['FILING_DEADLINE'] = $date;
                    break;
                }
            }
        }

        // Add standard deductions note from symbolic knowledge
        foreach ($this->standard_deductions as $c => $deds) {
            if (stripos($country, $c) !== false
                || stripos($c, $country) !== false) {
                $ded_text = [];
                foreach ($deds as $name => $amount) {
                    $ded_text[] = "$name: $" . number_format($amount);
                }
                $result['_symbolic_deductions'] = implode(', ', $ded_text);
                break;
            }
        }

        // Verify COMPLIANCE_RISK makes sense
        $net_tax = $this->num($result['NET_TAX'] ?? 0);
        $gross   = $this->num($result['GROSS_INCOME'] ?? 0);
        if ($gross > 0) {
            $rate = ($net_tax / $gross) * 100;
            if (!isset($result['COMPLIANCE_RISK'])
                || empty($result['COMPLIANCE_RISK'])) {
                if ($rate > 35) {
                    $result['COMPLIANCE_RISK'] = 'High';
                } elseif ($rate > 20) {
                    $result['COMPLIANCE_RISK'] = 'Medium';
                } else {
                    $result['COMPLIANCE_RISK'] = 'Low';
                }
            }
        }

        return $result;
    }

    private function fillMissingReportFields(array $result): array {
        $defaults = [
            'COUNTRY'            => 'Not detected',
            'GROSS_INCOME'       => '0',
            'TOTAL_DEDUCTIONS'   => '0',
            'TAXABLE_INCOME'     => '0',
            'GROSS_TAX'          => '0',
            'TAX_CREDITS'        => '0',
            'NET_TAX'            => '0',
            'TAX_WITHHELD'       => '0',
            'REFUND_OR_OWED'     => '0',
            'EFFECTIVE_RATE'     => '0',
            'STATUS'             => 'BALANCED',
            'COMPLIANCE_RISK'    => 'Low',
            'MISSING_DOCS'       => 'None',
            'TOP_RECOMMENDATION_1' => 'Review all income sources for accuracy',
            'TOP_RECOMMENDATION_2' => 'Ensure all eligible deductions are claimed',
            'TOP_RECOMMENDATION_3' => 'File before the deadline to avoid penalties',
            'FILING_DEADLINE'    => 'Check local tax authority',
            'BRACKET_1'          => 'N/A',
            'BRACKET_2'          => 'N/A',
            'BRACKET_3'          => 'N/A',
        ];

        foreach ($defaults as $key => $default) {
            if (empty($result[$key])) {
                $result[$key] = $default;
            }
        }

        return $result;
    }

    // ── CONFIDENCE SCORING ────────────────────────────────
    private function scoreCalculationConfidence(
        array $result,
        array $inputs
    ): float {
        $score  = 100.0;
        $checks = $this->rule_checks;

        foreach ($checks as $check) {
            if (!$check['pass']) {
                switch ($check['severity']) {
                    case 'critical': $score -= 20; break;
                    case 'warning':  $score -= 8;  break;
                    case 'info':     $score -= 3;  break;
                }
            }
        }

        // Bonus: if symbolic cross-check passed
        $symbolic = array_filter(
            $checks, fn($c) =>
            ($c['rule'] === 'symbolic_tax_cross_check')
            && $c['pass']
        );
        if (!empty($symbolic)) $score += 5;

        // Penalty per retry
        $score -= ($this->retry_count * 5);

        return max(0, min(100, round($score, 2)));
    }

    private function scoreComparisonConfidence(
        array $result,
        array $a_checks,
        array $b_checks
    ): float {
        $a_fails = count(array_filter(
            $a_checks, fn($c) => !$c['pass'] && $c['severity'] === 'critical'
        ));
        $b_fails = count(array_filter(
            $b_checks, fn($c) => !$c['pass'] && $c['severity'] === 'critical'
        ));
        $score   = 100 - ($a_fails * 15) - ($b_fails * 15);
        return max(0, min(100, round($score, 2)));
    }

    private function scoreReportConfidence(array $result): float {
        $filled  = 0;
        $total   = 10;
        $fields  = [
            'GROSS_INCOME', 'NET_TAX', 'STATUS',
            'EFFECTIVE_RATE', 'COUNTRY', 'FILING_DEADLINE',
            'TOP_RECOMMENDATION_1', 'COMPLIANCE_RISK',
            'BRACKET_1', 'REFUND_OR_OWED'
        ];

        foreach ($fields as $f) {
            if (!empty($result[$f])
                && $result[$f] !== '0'
                && $result[$f] !== 'N/A') {
                $filled++;
            }
        }

        return round(($filled / $total) * 92.5, 2);
    }

    // ╔══════════════════════════════════════════════════╗
    // ║        NEURAL LAYER — GROQ API WRAPPER           ║
    // ╚══════════════════════════════════════════════════╝

    private function callNeuralLayer(
        array  $messages,
        string $model,
        int    $max_tokens = 800
    ): string {
        $payload = [
            'model'       => $model,
            'messages'    => $messages,
            'max_tokens'  => $max_tokens,
            'temperature' => $this->temperature,
        ];

        $this->log('NEURAL_CALL', 'callNeuralLayer', [
            'model'      => $model,
            'max_tokens' => $max_tokens,
            'messages'   => count($messages),
        ]);

        $ch = curl_init($this->api_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->api_key,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT    => 90,
        ]);

        $response  = curl_exec($ch);
        $err       = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            $this->log('ERROR', 'callNeuralLayer', ['error' => $err]);
            return "AI Error (curl): $err";
        }

        $data = json_decode($response, true);

        if (isset($data['error'])) {
            $this->log('ERROR', 'callNeuralLayer',
                ['api_error' => $data['error']['message']]);
            return 'API Error: ' . $data['error']['message'];
        }

        $content = $data['choices'][0]['message']['content'] ?? '';
        $this->last_raw = $content;

        $this->log('NEURAL_RESPONSE', 'callNeuralLayer', [
            'http_code'     => $http_code,
            'response_len'  => strlen($content),
            'model'         => $model,
        ]);

        return $content;
    }

    // ╔══════════════════════════════════════════════════╗
    // ║         COMMAND BUILDERS                         ║
    // ╚══════════════════════════════════════════════════╝

    private function buildAnalysisCommand(
        string  $extracted_text,
        array   $file_info,
        ?string $tax_rules_text
    ): string {
        $client   = $file_info['client_name']   ?? 'N/A';
        $year     = $file_info['tax_year']       ?? 'N/A';
        $notes    = $file_info['client_notes']   ?? '';
        $filename = $file_info['file_name']      ?? '';

        return 'You are TaxStart AI, an expert international tax advisor.

[COMMAND: ANALYZE_DOCUMENT]
[ENGINE: TaxStartAI v1.0 Neuro-Symbolic]

CRITICAL: Extract ALL real numbers. Never invent figures.

CLIENT: ' . $client . '
TAX YEAR: ' . $year . '
FILE: ' . $filename . '
NOTES: ' . $notes . '
' . ($tax_rules_text ? "\n[SYMBOLIC TAX RULES INJECTED]\n$tax_rules_text\n" : '') . '

[REQUIRED OUTPUT STRUCTURE]
## COUNTRY AND TAX JURISDICTION DETECTED
## REQUIRED DOCUMENTS CHECKLIST
## EXTRACTED FINANCIAL DATA
## TAX CALCULATION BREAKDOWN
## DEDUCTIONS AND CREDITS ANALYSIS
## ISSUES AND FLAGS
## TAX ADVISORY AND RECOMMENDATIONS
## IMPORTANT DEADLINES
## NEXT STEPS FOR ADVISOR
## TAX SUMMARY SCORECARD';
    }

    private function buildAnalysisMessages(
        string  $command,
        string  $extracted_text,
        array   $file_info,
        bool    $is_image
    ): array {
        $file_type = strtoupper($file_info['file_type'] ?? '');
        $file_path = __DIR__ . '/../' . ($file_info['file_path'] ?? '');

        $system_msg = [
            'role'    => 'system',
            'content' => 'You are TaxStart AI — a Neuro-Symbolic tax advisor. '
                . 'The symbolic layer has already validated input. '
                . 'Extract real numbers only. Use plain text section headers.'
        ];

        // Image files — Vision AI
        if (in_array($file_type, ['JPG', 'JPEG', 'PNG'])
            && file_exists($file_path)) {
            $image_data = base64_encode(file_get_contents($file_path));
            $mime       = ($file_type === 'PNG') ? 'image/png' : 'image/jpeg';
            return [
                $system_msg,
                ['role' => 'user', 'content' => [
                    ['type' => 'text',      'text' => $command],
                    ['type' => 'image_url', 'image_url' => [
                        'url' => "data:$mime;base64,$image_data"
                    ]],
                ]]
            ];
        }

        // Text files
        $doc_content = ($extracted_text === 'IMAGE_FILE'
                     || $extracted_text === 'SCANNED_PDF')
            ? "[Document sent as image to Vision AI]"
            : "===DOCUMENT===\n$extracted_text\n===END===";

        return [
            $system_msg,
            ['role' => 'user', 'content' => $command . "\n\n" . $doc_content]
        ];
    }

    private function buildCalculationCommand(
        array $inputs,
        int   $attempt = 1
    ): string {
        $country     = $inputs['country']        ?? 'Canada';
        $year        = $inputs['tax_year']        ?? date('Y');
        $status      = $inputs['filing_status']   ?? 'Single';
        $employment  = $inputs['employment']      ?? 0;
        $self_emp    = $inputs['self_employment'] ?? 0;
        $rental      = $inputs['rental']          ?? 0;
        $investment  = $inputs['investment']      ?? 0;
        $other       = $inputs['other_income']    ?? 0;
        $rrsp        = $inputs['rrsp']            ?? 0;
        $childcare   = $inputs['childcare']       ?? 0;
        $medical     = $inputs['medical']         ?? 0;
        $charitable  = $inputs['charitable']      ?? 0;
        $business    = $inputs['business_exp']    ?? 0;
        $other_ded   = $inputs['other_ded']       ?? 0;
        $withheld    = $inputs['tax_withheld']    ?? 0;
        $tax_rules   = $inputs['_tax_rules']      ?? '';
        $hint        = $inputs['_correction_hint'] ?? '';

        // Symbolic pre-calculation for Canada
        $gross    = $employment + $self_emp + $rental + $investment + $other;
        $total_ded = $rrsp + $childcare + $medical + $charitable + $business + $other_ded;
        $taxable   = max(0, $gross - $total_ded);

        // Symbolic bracket cross-reference
        $sym_note = '';
        if (isset($this->tax_brackets[$country][$year])) {
            $sym_tax  = $this->calculateSymbolicTax($taxable, $country, $year);
            $sym_note = "\n[SYMBOLIC CROSS-CHECK: Expected gross tax ≈ \$"
                      . number_format($sym_tax, 2) . " based on "
                      . $country . " $year brackets]";
        }

        $retry_note = $attempt > 1
            ? "\n[RETRY $attempt — CORRECTION REQUIRED: $hint]"
            : '';

        return '[COMMAND: CALCULATE_TAX]
[ENGINE: TaxStartAI v1.0 Neuro-Symbolic]
[ATTEMPT: ' . $attempt . ']' . $retry_note . $sym_note . '
' . ($tax_rules ? "[OFFICIAL TAX RULES]\n$tax_rules\n" : '') . '

COUNTRY: ' . $country . '
TAX YEAR: ' . $year . '
FILING STATUS: ' . $status . '
[SYMBOLIC PRE-CALCULATION]
Gross Income = ' . $gross . '
Total Deductions = ' . $total_ded . '
Estimated Taxable Income = ' . $taxable . '

[INCOME BREAKDOWN]
Employment: ' . $employment . '
Self-Employment: ' . $self_emp . '
Rental: ' . $rental . '
Investment: ' . $investment . '
Other: ' . $other . '

[DEDUCTIONS]
RRSP/Retirement: ' . $rrsp . '
Childcare: ' . $childcare . '
Medical: ' . $medical . '
Charitable: ' . $charitable . '
Business: ' . $business . '
Other: ' . $other_ded . '

Tax Withheld: ' . $withheld . '

Return ONLY:
GROSS_INCOME: [number]
TOTAL_DEDUCTIONS: [number]
TAXABLE_INCOME: [number]
GROSS_TAX: [number]
TAX_CREDITS: [number]
NET_TAX: [number]
TAX_WITHHELD: [number]
REFUND_OR_OWED: [positive=refund, negative=owed]
EFFECTIVE_RATE: [percentage number only]
STATUS: [REFUND or OWED]
BRACKET_1_INCOME: [number]
BRACKET_1_RATE: [number]
BRACKET_1_TAX: [number]
BRACKET_2_INCOME: [number]
BRACKET_2_RATE: [number]
BRACKET_2_TAX: [number]
BRACKET_3_INCOME: [number]
BRACKET_3_RATE: [number]
BRACKET_3_TAX: [number]';
    }

    private function buildComparisonCommand(
        array   $a,
        array   $b,
        string  $country,
        ?string $tax_rules
    ): string {
        return '[COMMAND: COMPARE_SCENARIOS]
[ENGINE: TaxStartAI v1.0 Neuro-Symbolic]
' . ($tax_rules ? "[OFFICIAL TAX RULES]\n$tax_rules\n" : '') . '

COUNTRY: ' . $country . '
TAX YEAR: ' . date('Y') . '

--- SCENARIO A: ' . ($a['label'] ?? 'Scenario A') . ' ---
Filing Status: ' . ($a['filing_status'] ?? 'Single') . '
Employment: ' . ($a['employment'] ?? 0) . '
Self-Employment: ' . ($a['self_employment'] ?? 0) . '
Rental: ' . ($a['rental'] ?? 0) . '
Investment: ' . ($a['investment'] ?? 0) . '
Other Income: ' . ($a['other_income'] ?? 0) . '
RRSP: ' . ($a['rrsp'] ?? 0) . '
Childcare: ' . ($a['childcare'] ?? 0) . '
Medical: ' . ($a['medical'] ?? 0) . '
Charitable: ' . ($a['charitable'] ?? 0) . '
Business Expenses: ' . ($a['business_expense'] ?? 0) . '
Other Deductions: ' . ($a['other_deductions'] ?? 0) . '
Tax Withheld: ' . ($a['tax_withheld'] ?? 0) . '

--- SCENARIO B: ' . ($b['label'] ?? 'Scenario B') . ' ---
Filing Status: ' . ($b['filing_status'] ?? 'Single') . '
Employment: ' . ($b['employment'] ?? 0) . '
Self-Employment: ' . ($b['self_employment'] ?? 0) . '
Rental: ' . ($b['rental'] ?? 0) . '
Investment: ' . ($b['investment'] ?? 0) . '
Other Income: ' . ($b['other_income'] ?? 0) . '
RRSP: ' . ($b['rrsp'] ?? 0) . '
Childcare: ' . ($b['childcare'] ?? 0) . '
Medical: ' . ($b['medical'] ?? 0) . '
Charitable: ' . ($b['charitable'] ?? 0) . '
Business Expenses: ' . ($b['business_expense'] ?? 0) . '
Other Deductions: ' . ($b['other_deductions'] ?? 0) . '
Tax Withheld: ' . ($b['tax_withheld'] ?? 0) . '

Return ONLY:
SCENARIO_A_LABEL: [label]
SCENARIO_A_GROSS_INCOME: [number]
SCENARIO_A_TOTAL_DEDUCTIONS: [number]
SCENARIO_A_TAXABLE_INCOME: [number]
SCENARIO_A_GROSS_TAX: [number]
SCENARIO_A_CREDITS: [number]
SCENARIO_A_NET_TAX: [number]
SCENARIO_A_TAX_WITHHELD: [number]
SCENARIO_A_REFUND_OR_OWED: [number]
SCENARIO_A_EFFECTIVE_RATE: [number]
SCENARIO_A_STATUS: [REFUND or OWED]
SCENARIO_B_LABEL: [label]
SCENARIO_B_GROSS_INCOME: [number]
SCENARIO_B_TOTAL_DEDUCTIONS: [number]
SCENARIO_B_TAXABLE_INCOME: [number]
SCENARIO_B_GROSS_TAX: [number]
SCENARIO_B_CREDITS: [number]
SCENARIO_B_NET_TAX: [number]
SCENARIO_B_TAX_WITHHELD: [number]
SCENARIO_B_REFUND_OR_OWED: [number]
SCENARIO_B_EFFECTIVE_RATE: [number]
SCENARIO_B_STATUS: [REFUND or OWED]
BETTER_SCENARIO: [A or B]
DIFFERENCE: [absolute difference in net tax]';
    }

    private function buildReportCommand(array $analysis): string {
        return '[COMMAND: GENERATE_REPORT_DATA]
[ENGINE: TaxStartAI v1.0 Neuro-Symbolic]

CLIENT: ' . ($analysis['client_name'] ?? 'N/A') . '
TAX YEAR: ' . ($analysis['tax_year'] ?? 'N/A') . '

[ANALYSIS TEXT]
' . substr($analysis['ai_advice'] ?? '', 0, 3000) . '

Return ONLY:
COUNTRY: [detected country]
GROSS_INCOME: [number only]
TOTAL_DEDUCTIONS: [number only]
TAXABLE_INCOME: [number only]
GROSS_TAX: [number only]
TAX_CREDITS: [number only]
NET_TAX: [number only]
TAX_WITHHELD: [number only]
REFUND_OR_OWED: [positive=refund, negative=owed]
EFFECTIVE_RATE: [percentage number only]
STATUS: [REFUND or OWED or BALANCED]
COMPLIANCE_RISK: [Low or Medium or High]
MISSING_DOCS: [comma list or None]
TOP_RECOMMENDATION_1: [one sentence]
TOP_RECOMMENDATION_2: [one sentence]
TOP_RECOMMENDATION_3: [one sentence]
FILING_DEADLINE: [date or N/A]
BRACKET_1: [range and rate]
BRACKET_2: [range and rate or N/A]
BRACKET_3: [range and rate or N/A]';
    }

    // ╔══════════════════════════════════════════════════╗
    // ║            UTILITY METHODS                       ║
    // ╚══════════════════════════════════════════════════╝

    private function parseKeyValue(string $raw): array {
        $result = [];
        $lines  = explode("\n", $raw);
        foreach ($lines as $line) {
            $line = trim($line);
            if (strpos($line, ':') !== false) {
                [$key, $val]        = explode(':', $line, 2);
                $result[trim($key)] = trim($val);
            }
        }
        return $result;
    }

    private function extractScenario(array $result, string $letter): array {
        $out = [];
        foreach ($result as $key => $val) {
            if (str_starts_with($key, "SCENARIO_{$letter}_")) {
                $new_key       = str_replace("SCENARIO_{$letter}_", '', $key);
                $out[$new_key] = $val;
            }
        }
        return $out;
    }

    private function num(mixed $val): float {
        return (float) preg_replace('/[^0-9.\-]/', '', (string)($val ?? '0'));
    }

    private function log(
        string $type,
        string $method,
        array  $data = []
    ): void {
        $this->execution_log[] = [
            'time'   => microtime(true),
            'type'   => $type,
            'method' => $method,
            'data'   => $data,
        ];
    }
}
