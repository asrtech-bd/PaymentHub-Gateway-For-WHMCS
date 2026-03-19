<?php
/**
 * PaymentHub WHMCS Module — Auto-Update AJAX Handler
 *
 * Handles "Check for Updates" and "Update Now" requests from the admin config page.
 * Only accessible by authenticated WHMCS admins.
 */

// Load WHMCS bootstrap
require_once __DIR__ . '/../../../init.php';

// Verify admin authentication
$admin = new \WHMCS\Auth();
if (!$admin->isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
    exit;
}

// Load the gateway module functions
require_once __DIR__ . '/../paymenthub.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'check':
        $result = paymenthub_checkForUpdate();
        echo json_encode($result);
        break;

    case 'update':
        // First check what's available
        $check = paymenthub_checkForUpdate();
        if (!$check['success']) {
            echo json_encode($check);
            break;
        }

        if (!$check['update_available']) {
            echo json_encode(['success' => true, 'version' => $check['current_version'], 'message' => 'Already up to date.']);
            break;
        }

        $downloadUrl = $check['download_url'] ?? '';
        if (!$downloadUrl) {
            echo json_encode(['success' => false, 'error' => 'No download URL found in release.']);
            break;
        }

        $result = paymenthub_performUpdate($downloadUrl);
        echo json_encode($result);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action.']);
        break;
}
