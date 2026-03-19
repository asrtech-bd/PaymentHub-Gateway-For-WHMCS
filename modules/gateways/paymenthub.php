<?php
/**
 * PaymentHub WHMCS Payment Gateway Module
 *
 * Accept Bitcoin (BTC) and USDT (TRC-20) payments via PaymentHub.
 *
 * @see https://www.paymenthub.net/docs/api
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

define('PAYMENTHUB_MODULE_VERSION', '1.3.0');
define('PAYMENTHUB_GITHUB_REPO', 'PaymentHubBD/whmcs-paymenthub');
define('PAYMENTHUB_GITHUB_API', 'https://api.github.com/repos/' . PAYMENTHUB_GITHUB_REPO . '/releases/latest');

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
            'Description' => 'Which cryptocurrency to accept. "All" lets the customer choose at checkout.',
        ],
        'fiatCurrency' => [
            'FriendlyName' => 'Fiat Currency',
            'Type' => 'text',
            'Size' => '5',
            'Default' => 'USD',
            'Description' => 'Invoice currency code (e.g. USD, EUR, GBP).',
        ],
        'callbackUrl' => [
            'FriendlyName' => 'Webhook Callback URL',
            'Type' => 'yesno',
            'Description' => paymenthub_renderCallbackUrl(),
        ],
        'moduleUpdate' => [
            'FriendlyName' => 'Module Updates',
            'Type' => 'yesno',
            'Description' => paymenthub_renderUpdatePanel(),
        ],
    ];
}

/**
 * Build the callback URL display with one-click copy for admin config.
 */
function paymenthub_renderCallbackUrl(): string
{
    // Detect WHMCS system URL
    $systemUrl = \WHMCS\Config\Setting::getValue('SystemURL')
        ?? \WHMCS\Config\Setting::getValue('SystemSSLURL')
        ?? '';
    $systemUrl = rtrim($systemUrl, '/');

    if (!$systemUrl) {
        return 'Save settings first — callback URL will appear here.';
    }

    $callbackUrl = $systemUrl . '/modules/gateways/callback/paymenthub.php';
    $id = 'ph_callback_url';

    return '<div style="margin-top:4px;">'
        . '<input type="text" id="' . $id . '" value="' . htmlspecialchars($callbackUrl, ENT_QUOTES, 'UTF-8') . '" '
        . 'readonly style="width:500px;padding:6px 10px;border:1px solid #ccc;border-radius:4px;'
        . 'font-family:monospace;font-size:13px;background:#f9f9f9;color:#333;cursor:text;" />'
        . ' <button type="button" onclick="(function(){'
        . 'var i=document.getElementById(\'' . $id . '\');i.select();i.setSelectionRange(0,99999);'
        . 'document.execCommand(\'copy\');'
        . 'var b=event.target;b.textContent=\'Copied!\';b.style.background=\'#22c55e\';b.style.borderColor=\'#22c55e\';'
        . 'setTimeout(function(){b.textContent=\'Copy\';b.style.background=\'\';b.style.borderColor=\'\';},2000);'
        . '})()" style="padding:6px 16px;border:1px solid #6366f1;border-radius:4px;'
        . 'background:#6366f1;color:#fff;font-size:13px;font-weight:600;cursor:pointer;">Copy</button>'
        . '<br><small style="color:#666;">Paste this URL in your PaymentHub merchant webhook settings.</small>'
        . '</div>';
}

/**
 * Render the auto-update panel in admin config.
 */
function paymenthub_renderUpdatePanel(): string
{
    $currentVersion = PAYMENTHUB_MODULE_VERSION;
    $ajaxUrl = 'modules/gateways/paymenthub/update.php';

    return '<div id="ph-update-panel" style="margin-top:4px;">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
            <span style="font-size:13px;">Installed: <strong>v' . $currentVersion . '</strong></span>
            <span id="ph-remote-version" style="font-size:13px;"></span>
            <span id="ph-update-badge" style="display:none;background:#f59e0b;color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;">Update Available</span>
        </div>
        <div style="display:flex;gap:8px;">
            <button type="button" id="ph-check-btn" onclick="phCheckUpdate()" style="padding:6px 16px;border:1px solid #6366f1;border-radius:4px;background:#6366f1;color:#fff;font-size:13px;font-weight:600;cursor:pointer;">Check for Updates</button>
            <button type="button" id="ph-update-btn" onclick="phDoUpdate()" style="display:none;padding:6px 16px;border:1px solid #22c55e;border-radius:4px;background:#22c55e;color:#fff;font-size:13px;font-weight:600;cursor:pointer;">Update Now</button>
        </div>
        <div id="ph-update-status" style="margin-top:8px;font-size:13px;"></div>
        <div id="ph-changelog" style="display:none;margin-top:8px;padding:10px;background:#f9f9f9;border:1px solid #e5e7eb;border-radius:4px;font-size:12px;max-height:150px;overflow-y:auto;"></div>
    </div>
    <script>
    var phAjaxUrl="' . $ajaxUrl . '";
    function phSetStatus(msg,color){document.getElementById("ph-update-status").innerHTML=\'<span style="color:\'+color+\'">\'+msg+\'</span>\';}
    function phCheckUpdate(){
        var btn=document.getElementById("ph-check-btn");
        btn.disabled=true;btn.textContent="Checking...";
        phSetStatus("","#666");
        fetch(phAjaxUrl+"?action=check",{credentials:"same-origin"})
        .then(function(r){return r.json();})
        .then(function(d){
            btn.disabled=false;btn.textContent="Check for Updates";
            if(!d.success){phSetStatus(d.error||"Check failed.","#ef4444");return;}
            document.getElementById("ph-remote-version").innerHTML="Latest: <strong>v"+d.latest_version+"</strong>";
            if(d.update_available){
                document.getElementById("ph-update-badge").style.display="inline";
                document.getElementById("ph-update-btn").style.display="inline-block";
                phSetStatus("New version v"+d.latest_version+" is available.","#f59e0b");
                if(d.changelog){
                    var cl=document.getElementById("ph-changelog");
                    cl.innerHTML="<strong>Changelog:</strong><br>"+d.changelog.replace(/\\n/g,"<br>");
                    cl.style.display="block";
                }
            }else{
                document.getElementById("ph-update-badge").style.display="none";
                document.getElementById("ph-update-btn").style.display="none";
                document.getElementById("ph-changelog").style.display="none";
                phSetStatus("You are running the latest version.","#22c55e");
            }
        })
        .catch(function(e){btn.disabled=false;btn.textContent="Check for Updates";phSetStatus("Network error: "+e.message,"#ef4444");});
    }
    function phDoUpdate(){
        if(!confirm("This will update the PaymentHub module files. Continue?"))return;
        var btn=document.getElementById("ph-update-btn");
        btn.disabled=true;btn.textContent="Updating...";
        phSetStatus("Downloading and installing update...","#6366f1");
        fetch(phAjaxUrl+"?action=update",{credentials:"same-origin"})
        .then(function(r){return r.json();})
        .then(function(d){
            btn.disabled=false;btn.textContent="Update Now";
            if(!d.success){phSetStatus("Update failed: "+(d.error||"Unknown error"),"#ef4444");return;}
            phSetStatus("Updated to v"+d.version+" successfully! Reload this page.","#22c55e");
            document.getElementById("ph-update-badge").style.display="none";
            btn.style.display="none";
        })
        .catch(function(e){btn.disabled=false;btn.textContent="Update Now";phSetStatus("Update error: "+e.message,"#ef4444");});
    }
    </script>';
}

/**
 * Check GitHub releases for latest module version.
 */
function paymenthub_checkForUpdate(): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => PAYMENTHUB_GITHUB_API,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'PaymentHub-WHMCS/' . PAYMENTHUB_MODULE_VERSION,
        CURLOPT_HTTPHEADER => ['Accept: application/vnd.github.v3+json'],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['success' => false, 'error' => 'Connection failed: ' . $error];
    }

    $data = json_decode($response, true);
    if ($httpCode !== 200 || !$data || !isset($data['tag_name'])) {
        return ['success' => false, 'error' => 'Unable to fetch release info from GitHub.'];
    }

    // Strip leading "v" from tag (e.g. "v1.2.0" -> "1.2.0")
    $latestVersion = ltrim($data['tag_name'], 'vV');
    $updateAvailable = version_compare($latestVersion, PAYMENTHUB_MODULE_VERSION, '>');

    // Find the .zip asset or fall back to GitHub's auto-generated zipball
    $downloadUrl = $data['zipball_url'] ?? '';
    if (!empty($data['assets'])) {
        foreach ($data['assets'] as $asset) {
            if (str_ends_with($asset['name'], '.zip')) {
                $downloadUrl = $asset['browser_download_url'];
                break;
            }
        }
    }

    return [
        'success' => true,
        'current_version' => PAYMENTHUB_MODULE_VERSION,
        'latest_version' => $latestVersion,
        'update_available' => $updateAvailable,
        'changelog' => $data['body'] ?? '',
        'download_url' => $downloadUrl,
        'html_url' => $data['html_url'] ?? '',
    ];
}

/**
 * Download and install module update from GitHub.
 */
function paymenthub_performUpdate(string $downloadUrl): array
{
    $moduleDir = __DIR__;
    $tempFile = sys_get_temp_dir() . '/paymenthub_update_' . time() . '.zip';

    // Download the zip from GitHub
    $ch = curl_init();
    $fp = fopen($tempFile, 'w');
    if (!$fp) {
        return ['success' => false, 'error' => 'Cannot create temp file.'];
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $downloadUrl,
        CURLOPT_FILE => $fp,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'PaymentHub-WHMCS/' . PAYMENTHUB_MODULE_VERSION,
    ]);

    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    if ($error || $httpCode !== 200) {
        @unlink($tempFile);
        return ['success' => false, 'error' => 'Download failed: ' . ($error ?: 'HTTP ' . $httpCode)];
    }

    // Verify it's a valid zip
    $zip = new \ZipArchive();
    if ($zip->open($tempFile) !== true) {
        @unlink($tempFile);
        return ['success' => false, 'error' => 'Downloaded file is not a valid zip archive.'];
    }

    // Backup current files
    $backupDir = sys_get_temp_dir() . '/paymenthub_backup_' . time();
    @mkdir($backupDir, 0755, true);
    paymenthub_copyDir($moduleDir, $backupDir);

    // GitHub zipball has a root folder like "RepoName-tagname/"
    // Find the directory that contains modules/gateways/paymenthub.php or just paymenthub.php
    $zipRoot = '';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        // Match: */modules/gateways/paymenthub.php (repo zip structure)
        if (preg_match('#^([^/]+/)?modules/gateways/paymenthub\.php$#', $name, $m)) {
            $zipRoot = ($m[1] ?? '') . 'modules/gateways';
            break;
        }
        // Match: */paymenthub.php at gateway level (release asset zip)
        if (preg_match('#^([^/]+/)paymenthub\.php$#', $name, $m)) {
            $zipRoot = rtrim($m[1], '/');
            break;
        }
        // Match: paymenthub.php at root (flat zip)
        if ($name === 'paymenthub.php') {
            $zipRoot = '';
            break;
        }
    }

    // Extract to temp directory
    $extractDir = sys_get_temp_dir() . '/paymenthub_extract_' . time();
    $zip->extractTo($extractDir);
    $zip->close();

    // Determine source directory
    $sourceDir = $zipRoot ? $extractDir . '/' . $zipRoot : $extractDir;
    if (!is_dir($sourceDir) || !file_exists($sourceDir . '/paymenthub.php')) {
        @unlink($tempFile);
        paymenthub_removeDir($extractDir);
        return ['success' => false, 'error' => 'Invalid module zip structure — paymenthub.php not found.'];
    }

    // Copy extracted files into module directory
    paymenthub_copyDir($sourceDir, $moduleDir);

    // Cleanup
    @unlink($tempFile);
    paymenthub_removeDir($extractDir);
    paymenthub_removeDir($backupDir);

    // Read new version from the updated file
    $newContent = file_get_contents($moduleDir . '/paymenthub.php');
    $newVersion = PAYMENTHUB_MODULE_VERSION;
    if (preg_match("/PAYMENTHUB_MODULE_VERSION',\s*'([^']+)'/", $newContent, $m)) {
        $newVersion = $m[1];
    }

    return ['success' => true, 'version' => $newVersion];
}

/**
 * Recursively copy a directory.
 */
function paymenthub_copyDir(string $src, string $dst): void
{
    @mkdir($dst, 0755, true);
    $dir = opendir($src);
    while (($file = readdir($dir)) !== false) {
        if ($file === '.' || $file === '..') continue;
        $srcPath = $src . '/' . $file;
        $dstPath = $dst . '/' . $file;
        if (is_dir($srcPath)) {
            paymenthub_copyDir($srcPath, $dstPath);
        } else {
            copy($srcPath, $dstPath);
        }
    }
    closedir($dir);
}

/**
 * Recursively remove a directory.
 */
function paymenthub_removeDir(string $dir): void
{
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        is_dir($path) ? paymenthub_removeDir($path) : @unlink($path);
    }
    @rmdir($dir);
}

/**
 * Generate the payment button / redirect for the invoice.
 *
 * Called when a client views an unpaid invoice.
 */
function paymenthub_link($params): string
{
    $apiUrl = "https://www.paymenthub.net");
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
