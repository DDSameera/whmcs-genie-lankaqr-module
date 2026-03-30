<?php
/**
 * WHMCS LANKAQR — Callback / Payment Verification
 * ══════════════════════════════════════════════════════════════
 * Install path: /path/to/whmcs/modules/gateways/callback/lankaqr.php
 *
 * Handles two scenarios:
 *   1. action=check  — AJAX polling from checkout page
 *   2. (no action)   — Webhook / redirect from Genie (future use)
 * ══════════════════════════════════════════════════════════════
 */

// ── WHMCS Bootstrap ──────────────────────────────────────────
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

// ── Load gateway config ──────────────────────────────────────
$gatewayModuleName = 'lankaqr';
$gatewayParams     = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    die('Module not activated.');
}

$action    = $_GET['action'] ?? '';
$invoiceId = (int)($_GET['invoice_id'] ?? 0);

/* ═══════════════════════════════════════════════════════════════
   ACTION: check — AJAX payment status poll
   Called every N seconds from the checkout page JS
═══════════════════════════════════════════════════════════════ */
if ($action === 'check' && $invoiceId > 0) {
    header('Content-Type: application/json');

    // Get current invoice status from WHMCS
    $invoice = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);

    if (empty($invoice) || isset($invoice['result']) && $invoice['result'] === 'error') {
        echo json_encode(['status' => 'error', 'message' => 'Invoice not found']);
        exit;
    }

    $invoiceStatus = strtoupper($invoice['status'] ?? '');

    if ($invoiceStatus === 'PAID') {
        echo json_encode([
            'status'   => 'paid',
            'redirect' => $gatewayParams['systemurl'] . 'viewinvoice.php?id=' . $invoiceId,
        ]);
        exit;
    }

    if (in_array($invoiceStatus, ['CANCELLED', 'REFUNDED'])) {
        echo json_encode(['status' => 'failed']);
        exit;
    }

    // Still unpaid
    echo json_encode(['status' => 'pending']);
    exit;
}

/* ═══════════════════════════════════════════════════════════════
   ACTION: webhook / redirect (Genie notifies payment complete)
   Genie Biz can POST to this URL after payment confirmation.
   Set this as your "Redirect URL" / "Webhook URL" in Genie dashboard.
═══════════════════════════════════════════════════════════════ */

// Read raw POST body (Genie sends JSON)
$rawBody = file_get_contents('php://input');
$data    = json_decode($rawBody, true) ?? $_POST;

// Log incoming webhook for debugging
$logFile = __DIR__ . '/lankaqr_webhook.log';
file_put_contents($logFile,
    '[' . date('Y-m-d H:i:s') . '] ' . $_SERVER['REQUEST_METHOD'] . "\n"
    . 'Body: ' . $rawBody . "\n"
    . str_repeat('-', 60) . "\n",
    FILE_APPEND | LOCK_EX
);

// ── Extract payment details from Genie response ──────────────
// Genie webhook fields (adjust based on actual webhook payload):
$transactionId     = $data['id']                ?? $data['transactionId']  ?? '';
$customerReference = $data['customerReference'] ?? $data['externalId']     ?? '';
$paymentState      = strtoupper($data['state']  ?? $data['status']         ?? '');
$paidAmount        = (float)($data['amount']    ?? $data['payAmount']      ?? 0);

// Extract invoice ID from customerReference (format: INV-123)
if (empty($invoiceId) && preg_match('/INV-(\d+)/i', $customerReference, $m)) {
    $invoiceId = (int)$m[1];
}

if (!$invoiceId) {
    http_response_code(400);
    die('Could not determine invoice ID from: ' . htmlspecialchars($customerReference));
}

// ── Only process CONFIRMED payments ──────────────────────────
if (!in_array($paymentState, ['CONFIRMED', 'PAID', 'SUCCESS'])) {
    http_response_code(200);
    echo 'State ' . $paymentState . ' — no action taken.';
    exit;
}

// ── Verify invoice exists ─────────────────────────────────────
$invoice = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
if (isset($invoice['result']) && $invoice['result'] === 'error') {
    http_response_code(404);
    die('Invoice not found: ' . $invoiceId);
}

// ── Check for duplicate transaction ──────────────────────────
checkCbTransID($transactionId);

// ── Get invoice amount for validation ────────────────────────
$invoiceAmount = (float)($invoice['total'] ?? 0);

// ── Add payment to WHMCS ─────────────────────────────────────
$addPaymentResult = addInvoicePayment(
    $invoiceId,
    $transactionId,
    $invoiceAmount,  // use invoice amount, not Genie amount (Genie stores in cents sometimes)
    0,               // fees
    $gatewayModuleName
);

// ── Log result ───────────────────────────────────────────────
file_put_contents($logFile,
    '[' . date('Y-m-d H:i:s') . '] Payment processed'
    . ' | Invoice: ' . $invoiceId
    . ' | TxnID: ' . $transactionId
    . ' | Amount: ' . $invoiceAmount
    . ' | Result: ' . ($addPaymentResult ? 'SUCCESS' : 'FAILED/DUPLICATE')
    . "\n" . str_repeat('-', 60) . "\n",
    FILE_APPEND | LOCK_EX
);

http_response_code(200);
echo $addPaymentResult ? 'Payment recorded.' : 'Already recorded or failed.';
