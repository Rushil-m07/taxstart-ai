<?php
// ── CREDIT SYSTEM HELPER ─────────────────────────────

function getCurrentYear() {
    return (int)date('Y');
}

// Get advisor's available credits for a tier this year
function getAvailableCredits($pdo, $advisor_id, $tier) {
    $year = getCurrentYear();
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_credits),0) -
               COALESCE(SUM(used_credits),0)
               AS available
        FROM advisor_credits
        WHERE advisor_id = ? AND tier = ? AND year = ?
    ");
    $stmt->execute([$advisor_id, $tier, $year]);
    return (int)$stmt->fetchColumn();
}

// Get total credits for a tier this year
function getTotalCredits($pdo, $advisor_id, $tier) {
    $year = getCurrentYear();
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_credits),0) AS total
        FROM advisor_credits
        WHERE advisor_id = ? AND tier = ? AND year = ?
    ");
    $stmt->execute([$advisor_id, $tier, $year]);
    return (int)$stmt->fetchColumn();
}

// Get used credits for a tier this year
function getUsedCredits($pdo, $advisor_id, $tier) {
    $year = getCurrentYear();
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(used_credits),0) AS used
        FROM advisor_credits
        WHERE advisor_id = ? AND tier = ? AND year = ?
    ");
    $stmt->execute([$advisor_id, $tier, $year]);
    return (int)$stmt->fetchColumn();
}

// Check if advisor has welcome offer credits
function hasWelcomeOffer($pdo, $advisor_id) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM advisor_credits
        WHERE advisor_id = ? AND is_free = 1
    ");
    $stmt->execute([$advisor_id]);
    return (int)$stmt->fetchColumn() > 0;
}

// Initialize welcome offer for new advisor
function initWelcomeOffer($pdo, $advisor_id) {
    if (hasWelcomeOffer($pdo, $advisor_id)) return;
    $year = getCurrentYear();

    // 2 free silver
    $pdo->prepare("
        INSERT INTO advisor_credits
            (advisor_id, tier, total_credits,
             used_credits, year, is_free)
        VALUES (?, 'silver', 2, 0, ?, 1)
        ON DUPLICATE KEY UPDATE
            total_credits = total_credits + 2
    ")->execute([$advisor_id, $year]);

    // 1 free gold
    $pdo->prepare("
        INSERT INTO advisor_credits
            (advisor_id, tier, total_credits,
             used_credits, year, is_free)
        VALUES (?, 'gold', 1, 0, ?, 1)
        ON DUPLICATE KEY UPDATE
            total_credits = total_credits + 1
    ")->execute([$advisor_id, $year]);

    // Record in subscriptions
    $exp = date('Y-12-31 23:59:59');
    $pdo->prepare("
        INSERT INTO subscriptions
            (advisor_id, tier, credits_bought,
             price_paid, is_free, year, expires_at)
        VALUES (?, 'silver', 2, 0.00, 1, ?, ?)
    ")->execute([$advisor_id, $year, $exp]);

    $pdo->prepare("
        INSERT INTO subscriptions
            (advisor_id, tier, credits_bought,
             price_paid, is_free, year, expires_at)
        VALUES (?, 'gold', 1, 0.00, 1, ?, ?)
    ")->execute([$advisor_id, $year, $exp]);
}

// Use one credit (returns true if successful)
function useCredit($pdo, $advisor_id, $tier, $action, $client_id = null) {
    $year = getCurrentYear();

    // Find credit row with available credits
    $stmt = $pdo->prepare("
        SELECT credit_id,
               (total_credits - used_credits) AS available
        FROM advisor_credits
        WHERE advisor_id = ? AND tier = ? AND year = ?
          AND (total_credits - used_credits) > 0
        ORDER BY is_free DESC
        LIMIT 1
    ");
    $stmt->execute([$advisor_id, $tier, $year]);
    $credit = $stmt->fetch();

    if (!$credit || $credit['available'] <= 0) {
        return false;
    }

    // Deduct credit
    $pdo->prepare("
        UPDATE advisor_credits
        SET used_credits = used_credits + 1
        WHERE credit_id = ?
    ")->execute([$credit['credit_id']]);

    // Log usage
    $pdo->prepare("
        INSERT INTO credit_usage
            (advisor_id, credit_id, client_id, action)
        VALUES (?, ?, ?, ?)
    ")->execute([
        $advisor_id,
        $credit['credit_id'],
        $client_id,
        $action
    ]);

    return true;
}

// Add purchased credits
function addCredits($pdo, $advisor_id, $tier, $amount, $price, $payment_id = null) {
    $year = getCurrentYear();
    $exp  = date('Y-12-31 23:59:59');

    $pdo->prepare("
        INSERT INTO advisor_credits
            (advisor_id, tier, total_credits,
             used_credits, year, is_free)
        VALUES (?, ?, ?, 0, ?, 0)
        ON DUPLICATE KEY UPDATE
            total_credits = total_credits + ?
    ")->execute([$advisor_id, $tier, $amount, $year, $amount]);

    $pdo->prepare("
        INSERT INTO subscriptions
            (advisor_id, tier, credits_bought,
             price_paid, is_free, payment_id, year, expires_at)
        VALUES (?, ?, ?, ?, 0, ?, ?, ?)
    ")->execute([
        $advisor_id, $tier, $amount,
        $price, $payment_id, $year, $exp
    ]);
}

// Get current subscription prices from admin settings
function getSubscriptionPrices($pdo) {
    $stmt = $pdo->query("
        SELECT tier, price_yearly FROM subscription_pricing
    ");
    $prices = [];
    foreach ($stmt->fetchAll() as $row) {
        $prices[$row['tier']] = (float)$row['price_yearly'];
    }
    return $prices;
}

// Detect if scenario is international (needs gold)
function isInternationalScenario($country) {
    $domestic = ['Canada', 'canadian', 'CRA'];
    foreach ($domestic as $d) {
        if (stripos($country, $d) !== false) return false;
    }
    return true;
}

// Get full credit summary for advisor
function getCreditSummary($pdo, $advisor_id) {
    $year = getCurrentYear();
    return [
        'silver_total'     => getTotalCredits($pdo, $advisor_id, 'silver'),
        'silver_used'      => getUsedCredits($pdo,  $advisor_id, 'silver'),
        'silver_available' => getAvailableCredits($pdo, $advisor_id, 'silver'),
        'gold_total'       => getTotalCredits($pdo, $advisor_id, 'gold'),
        'gold_used'        => getUsedCredits($pdo,  $advisor_id,  'gold'),
        'gold_available'   => getAvailableCredits($pdo, $advisor_id, 'gold'),
        'year'             => $year
    ];
}
// Check if a client has an active (non-expired) credit
function clientHasActiveCredit($pdo, $client_id) {
    $stmt = $pdo->prepare("
        SELECT credit_used, credit_expires_at
        FROM clients WHERE client_id = ?
    ");
    $stmt->execute([$client_id]);
    $client = $stmt->fetch();

    if (!$client || !$client['credit_used']) return false;
    if (!$client['credit_expires_at']) return false;

    return strtotime($client['credit_expires_at']) >= strtotime('today');
}

// Check if client's credit is expired (had one but it ran out)
function clientCreditExpired($pdo, $client_id) {
    $stmt = $pdo->prepare("
        SELECT credit_used, credit_expires_at
        FROM clients WHERE client_id = ?
    ");
    $stmt->execute([$client_id]);
    $client = $stmt->fetch();

    if (!$client || !$client['credit_used']) return false;
    if (!$client['credit_expires_at']) return false;

    return strtotime($client['credit_expires_at']) < strtotime('today');
}

// Activate a credit for a client (set expiry to 1 year from now)
function activateClientCredit($pdo, $client_id, $tax_type) {
    $expires = date('Y-m-d', strtotime('+1 year'));
    $stmt = $pdo->prepare("
        UPDATE clients
        SET tax_type = ?, credit_used = 1, credit_expires_at = ?
        WHERE client_id = ?
    ");
    $stmt->execute([$tax_type, $expires, $client_id]);
}

// Get client's tax type
function getClientTaxType($pdo, $client_id) {
    $stmt = $pdo->prepare("SELECT tax_type FROM clients WHERE client_id = ?");
    $stmt->execute([$client_id]);
    return $stmt->fetchColumn() ?: 'domestic';
}

// Renew an expired client credit (uses another credit)
function renewClientCredit($pdo, $advisor_id, $client_id) {
    $tax_type = getClientTaxType($pdo, $client_id);
    $tier = ($tax_type === 'international') ? 'gold' : 'silver';

    if (!useCredit($pdo, $advisor_id, $tier, 'RENEW_CLIENT', $client_id)) {
        return false;
    }

    activateClientCredit($pdo, $client_id, $tax_type);
    return true;
}
?>