<?php
/**
 * PaymentHub WHMCS Payment Gateway Module
 *
 * Accept Bitcoin (BTC) and USDT (TRC-20) payments via PaymentHub.
 *
 * @see https://paymenthub.net/docs/api
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

/**
 * Module metadata.
 */
function paymenthub_MetaData(): array
{
    return [
        'DisplayName' => 'PaymentHub - Crypto Payments',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

/**
 * Admin configuration fields.
 */
function paymenthub_config(): array
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'PaymentHub - Crypto Payments',
        ],
        'apiUrl' => [
            'FriendlyName' => 'API URL',
            'Type' => 'text',
            'Size' => '60',
            'Default' => 'https://www.paymenthub.net',
            'Description' => 'Your PaymentHub instance URL (e.g. https://www.paymenthub.net).',
        ],
        'apiKey' => [
            'FriendlyName' => 'API Key',
            'Type' => 'password',
            'Size' => '60',
            'Description' => 'Your merchant API key from PaymentHub dashboard.',
        ],
        'webhookSecret' => [
            'FriendlyName' => 'Webhook Secret',
            'Type' => 'password',
            'Size' => '60',
            'Description' => 'Webhook secret for signature verification.',
        ],
        'coin' => [
            'FriendlyName' => 'Accepted Coin',
            'Type' => 'dropdown',
            'Options' => [
                'ALL' => 'All (Customer Chooses)',
                'BTC' => 'Bitcoin (BTC)',
                'USDT' => 'USDT (TRC-20)',
            ],
            'Default' => 'ALL',
            'Description' => 'Which cryptocurrency to accept.',
        ],
        'fiatCurrency' => [
            'FriendlyName' => 'Fiat Currency',
            'Type' => 'text',
            'Size' => '5',
            'Default' => 'USD',
            'Description' => 'Invoice currency code (e.g. USD, EUR, BDT).',
        ],
    ];
}

/**
 * Generate the payment button / redirect for the invoice.
 *
 * Called when a client views an unpaid invoice.
 */
function paymenthub_link($params): string
{
    $apiUrl = rtrim($params['apiUrl'] ?: 'https://www.paymenthub.net', '/');
    $apiKey = $params['apiKey'];
    $coin = $params['coin'] ?: 'ALL';
    $fiatCurrency = $params['fiatCurrency'] ?: 'USD';

    $invoiceId = $params['invoiceid'];
    $amount = $params['amount'];
    $clientId = $params['clientdetails']['userid'];

    // Build API request body
    $body = [
        'coin' => $coin,
        'amount' => (float) $amount,
        'fiat_currency' => $fiatCurrency,
        'order_id' => (string) $invoiceId,
        'callback_url' => $params['systemurl'] . 'viewinvoice.php?id=' . $invoiceId,
        'metadata' => [
            'whmcs_invoice_id' => $invoiceId,
            'client_id' => $clientId,
        ],
    ];

    // Create invoice via PaymentHub API
    $response = paymenthub_apiRequest($apiUrl . '/api/v1/invoices', $apiKey, 'POST', $body);

    if (!$response['success']) {
        $errorMsg = $response['error'] ?? 'Unknown error creating payment.';
        logTransaction('paymenthub', ['request' => $body, 'response' => $response], 'API Error: ' . $errorMsg);
        return '<div class="alert alert-danger">Unable to create crypto payment. Please try again or contact support.</div>';
    }

    $data = $response['data'];
    $paymentUrl = $data['payment_url'];
    $uuid = $data['uuid'];

    // Log the transaction for reference
    logTransaction('paymenthub', [
        'whmcs_invoice_id' => $invoiceId,
        'paymenthub_uuid' => $uuid,
        'payment_url' => $paymentUrl,
        'coin' => $data['coin'],
        'amount_crypto' => $data['amount_crypto'],
        'amount_fiat' => $data['amount_fiat'],
    ], 'Invoice Created');

    // Return a pay button that redirects to PaymentHub checkout
    $html = '<form method="GET" action="' . htmlspecialchars($paymentUrl, ENT_QUOTES, 'UTF-8') . '">';
    $html .= '<button type="submit" class="btn btn-primary" style="';
    $html .= 'background: linear-gradient(135deg, #6366f1, #8b5cf6); ';
    $html .= 'border: none; color: #fff; padding: 12px 32px; border-radius: 8px; ';
    $html .= 'font-size: 16px; font-weight: 600; cursor: pointer;">';
    $html .= '&#x20BF; Pay with Crypto';
    $html .= '</button>';
    $html .= '</form>';

    return $html;
}

/**
 * Make an HTTP request to the PaymentHub API.
 */
function paymenthub_apiRequest(string $url, string $apiKey, string $method = 'GET', ?array $body = null): array
{
    $ch = curl_init();

    $headers = [
        'X-API-Key: ' . $apiKey,
        'Accept: application/json',
        'Content-Type: application/json',
    ];

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'error' => 'cURL error: ' . $curlError];
    }

    $decoded = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300 && $decoded) {
        return $decoded;
    }

    return [
        'success' => false,
        'error' => $decoded['error'] ?? ('HTTP ' . $httpCode),
        'http_code' => $httpCode,
    ];
}
