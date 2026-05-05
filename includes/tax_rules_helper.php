<?php
function getActiveTaxRules($pdo) {
    $stmt = $pdo->query("
        SELECT extracted_text, file_name, description
        FROM tax_rules
        WHERE is_active = 1
        LIMIT 1
    ");
    $rule = $stmt->fetch();
    if ($rule && !empty($rule['extracted_text'])) {
        return "OFFICIAL TAX RULES DOCUMENT ("
             . $rule['file_name'] . "):\n"
             . substr($rule['extracted_text'], 0, 3000);
    }
    return null;
}
?>