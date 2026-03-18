<?php
/**
 * PaymentHub WHMCS Webhook Callback Handler
 *
 * Receives webhook notifications from PaymentHub when invoice status changes.
 * URL: https://your-whmcs.com/modules/gateways/callback/paymenthub.php
 *
 * Configure this URL as the webhook endpoint in your PaymentHub merchant settings.
 */

// Load WHMCS bootstrap
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

// Load gateway config
$gatewayModuleName = 'paymenthub';
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Ensure module is active
if (!$gatewayParams['type']) {
    logTransaction($gatewayModuleName, $_POST, 'Module Not Activated');
    http_response_code(500);
    die('Module not activated.');
}

// Read raw POST body
$payload = file_get_contents('php://input');
$data = json_decode($payload, true);

if (!$data) {
    logTransaction($gatewayModuleName, ['raw' => $payload], 'Invalid JSON Payload');
    http_response_code(400);
    die('Invalid payload.');
}

// Verify HMAC-SHA256 signature
$signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
$webhookSecret = $gatewayParams['webhookSecret'];

if ($webhookSecret) {
    $expectedSignature = hash_hmac('sha256', $payload, $webhookSecret);

    if (!hash_equals($expectedSignature, $signature)) {
        logTransaction($gatewayModuleName, [
            'received_signature' => $signature,
            'payload' => $data,
        ], 'Signature Verification Failed');
        http_response_code(403);
        die('Invalid signature.');
    }
}

// Extract webhook data
$event = $data['event'] ?? '';
$invoice = $data['invoice'] ?? [];
$orderId = $invoice['order_id'] ?? '';
$status = $invoice['status'] ?? '';
$uuid = $invoice['uuid'] ?? '';
$paidAmount = $invoice['paid_amount'] ?? 0;
$amountFiat = $invoice['amount_fiat'] ?? 0;
$coin = $invoice['selected_coin'] ?? $invoice['coin'] ?? '';
$paidTxid = $invoice['paid_txid'] ?? '';

// Log every webhook received
logTransaction($gatewayModuleName, $data, 'Webhook Received: ' . $event);

// Validate we have the required data
if (!$orderId || !$uuid) {
    logTransaction($gatewayModuleName, $data, 'Missing order_id or uuid');
    http_response_code(400);
    die('Missing required fields.');
}

// The order_id is our WHMCS invoice ID
$whmcsInvoiceId = (int) $orderId;

// Validate the invoice exists in WHMCS
$whmcsInvoice = localAPI('GetInvoice', ['invoiceid' => $whmcsInvoiceId]);

if ($whmcsInvoice['result'] !== 'success') {
    logTransaction($gatewayModuleName, [
        'whmcs_invoice_id' => $whmcsInvoiceId,
        'webhook_data' => $data,
    ], 'WHMCS Invoice Not Found');
    http_response_code(404);
    die('Invoice not found.');
}

// Handle events
switch ($event) {
    case 'invoice.paid':
        // Check for duplicate transaction
        if (checkCbTransID($uuid)) {
            logTransaction($gatewayModuleName, $data, 'Duplicate Transaction');
            http_response_code(200);
            die('Already processed.');
        }

        // Validate amount matches (with small tolerance for rounding)
        $whmcsTotal = (float) $whmcsInvoice['total'];
        $paidFiat = (float) $amountFiat;
        $tolerance = 0.01; // 1 cent tolerance

        if (abs($whmcsTotal - $paidFiat) > $tolerance) {
            logTransaction($gatewayModuleName, [
                'expected' => $whmcsTotal,
                'received' => $paidFiat,
                'webhook_data' => $data,
            ], 'Amount Mismatch');
            // Still process but log the discrepancy
        }

        // Build transaction fee description
        $transactionFee = '';
        $paymentNote = sprintf(
            'PaymentHub | %s | UUID: %s | TxID: %s | Paid: %s %s',
            $coin,
            $uuid,
            $paidTxid ?: 'N/A',
            $paidAmount,
            $coin
        );

        // Mark WHMCS invoice as paid
        addInvoicePayment(
            $whmcsInvoiceId,       // Invoice ID
            $uuid,                 // Transaction ID (PaymentHub UUID)
            $paidFiat,             // Amount paid (fiat)
            $transactionFee,       // Transaction fee
            $gatewayModuleName     // Gateway module name
        );

        logTransaction($gatewayModuleName, $data, 'Payment Successful - ' . $paymentNote);
        http_response_code(200);
        echo json_encode(['status' => 'ok', 'message' => 'Payment recorded.']);
        break;

    case 'invoice.confirming':
        logTransaction($gatewayModuleName, $data, sprintf(
            'Payment Confirming | Invoice #%d | UUID: %s | %s %s',
            $whmcsInvoiceId, $uuid, $paidAmount, $coin
        ));
        http_response_code(200);
        echo json_encode(['status' => 'ok', 'message' => 'Confirming logged.']);
        break;

    case 'invoice.expired':
        logTransaction($gatewayModuleName, $data, sprintf(
            'Invoice Expired | Invoice #%d | UUID: %s',
            $whmcsInvoiceId, $uuid
        ));
        http_response_code(200);
        echo json_encode(['status' => 'ok', 'message' => 'Expiry logged.']);
        break;

    case 'invoice.underpaid':
        logTransaction($gatewayModuleName, $data, sprintf(
            'Underpayment | Invoice #%d | UUID: %s | Expected: %s, Received: %s %s',
            $whmcsInvoiceId, $uuid, $amountFiat, $paidAmount, $coin
        ));
        http_response_code(200);
        echo json_encode(['status' => 'ok', 'message' => 'Underpayment logged.']);
        break;

    case 'invoice.created':
    case 'invoice.coin_selected':
        // Informational events - just log
        logTransaction($gatewayModuleName, $data, 'Event: ' . $event);
        http_response_code(200);
        echo json_encode(['status' => 'ok']);
        break;

    default:
        logTransaction($gatewayModuleName, $data, 'Unknown Event: ' . $event);
        http_response_code(200);
        echo json_encode(['status' => 'ok', 'message' => 'Unknown event logged.']);
        break;
}
