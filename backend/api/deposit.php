<?php
/**
 * Deposit API Endpoints
 * Handles payment initialization, verification, and deposit history
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/PaystackPayment.php';

$db = new Database();
$auth = new Auth($db);
$paystack = new PaystackPayment($db);

$action = $_GET['action'] ?? 'get_methods';
$method = $_SERVER['REQUEST_METHOD'];

// Require authentication for most endpoints
$user = null;
if (!in_array($action, ['verify_callback'])) {
    $user = $auth->getCurrentUser();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

try {
    switch ($action) {
        case 'get_methods':
            if ($method !== 'GET') {
                throw new Exception('Invalid method');
            }
            
            $methods = $paystack->getPaymentMethods();
            echo json_encode(['success' => true, 'data' => $methods]);
            break;

        case 'initialize':
            if ($method !== 'POST') {
                throw new Exception('Invalid method');
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            $amount = $data['amount'] ?? null;
            
            if (!$amount) {
                throw new Exception('Amount is required');
            }
            
            $result = $paystack->initializePayment($user['id'], $amount, $user['email']);
            echo json_encode($result);
            break;

        case 'verify':
            if ($method !== 'GET') {
                throw new Exception('Invalid method');
            }
            
            $reference = $_GET['reference'] ?? null;
            if (!$reference) {
                throw new Exception('Reference is required');
            }
            
            $result = $paystack->verifyPayment($reference);
            echo json_encode($result);
            break;

        case 'verify_callback':
            // This is called by Paystack, no authentication needed
            $reference = $_GET['reference'] ?? null;
            
            if (!$reference) {
                http_response_code(400);
                echo json_encode(['error' => 'Reference is required']);
                exit;
            }
            
            $result = $paystack->verifyPayment($reference);
            
            if ($result['success']) {
                // Redirect to success page
                header('Location: ../../frontend/html/deposit-success.html?reference=' . $reference);
            } else {
                // Redirect to failed page
                header('Location: ../../frontend/html/deposit-failed.html?reference=' . $reference);
            }
            exit;

        case 'history':
            if ($method !== 'GET') {
                throw new Exception('Invalid method');
            }
            
            $limit = $_GET['limit'] ?? 50;
            $history = $paystack->getDepositHistory($user['id'], $limit);
            echo json_encode(['success' => true, 'data' => $history]);
            break;

        case 'stats':
            if ($method !== 'GET') {
                throw new Exception('Invalid method');
            }
            
            $stats = $paystack->getDepositStats($user['id']);
            echo json_encode(['success' => true, 'data' => $stats]);
            break;

        default:
            throw new Exception('Unknown action');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
