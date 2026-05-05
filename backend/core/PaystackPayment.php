<?php
/**
 * Paystack Integration for Deposits
 * Handles payment processing and wallet top-up
 */

class PaystackPayment {
    private $public_key;
    private $secret_key;
    private $base_url = 'https://api.paystack.co';
    private $db;

    public function __construct($db, $secret_key = null, $public_key = null) {
        $this->db = $db;
        $this->secret_key = $secret_key ?? $_ENV['PAYSTACK_SECRET_KEY'] ?? getenv('PAYSTACK_SECRET_KEY');
        $this->public_key = $public_key ?? $_ENV['PAYSTACK_PUBLIC_KEY'] ?? getenv('PAYSTACK_PUBLIC_KEY');

        if (!$this->secret_key) {
            throw new Exception('Paystack secret key not configured');
        }
    }

    /**
     * Initialize payment transaction
     */
    public function initializePayment($user_id, $amount, $email) {
        try {
            // Validate inputs
            if ($amount < 100) {
                return ['success' => false, 'message' => 'Minimum deposit is $100 USD'];
            }

            if ($amount > 500000) {
                return ['success' => false, 'message' => 'Maximum deposit is $500,000 USD'];
            }

            // Create transaction record
            $reference = 'PALMOIL_' . $user_id . '_' . time() . '_' . rand(1000, 9999);

            $transaction_id = $this->db->insert('deposit_transactions', [
                'user_id' => $user_id,
                'reference' => $reference,
                'amount' => $amount,
                'email' => $email,
                'status' => 'pending',
                'created_at' => date('Y-m-d H:i:s')
            ]);

            if (!$transaction_id) {
                return ['success' => false, 'message' => 'Failed to create transaction record'];
            }

            // Initialize Paystack payment
            $response = $this->callPaystackAPI('/transaction/initialize', [
                'email' => $email,
                'amount' => $amount * 100, // Paystack uses cents
                'reference' => $reference,
                'callback_url' => $_ENV['APP_URL'] . '/backend/api/deposit.php?action=verify_callback'
            ]);

            if (!$response['success']) {
                return ['success' => false, 'message' => 'Failed to initialize payment'];
            }

            return [
                'success' => true,
                'message' => 'Payment initialized successfully',
                'authorization_url' => $response['data']['authorization_url'],
                'access_code' => $response['data']['access_code'],
                'reference' => $reference,
                'transaction_id' => $transaction_id
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Verify payment
     */
    public function verifyPayment($reference) {
        try {
            // Verify with Paystack
            $response = $this->callPaystackAPI("/transaction/verify/" . rawurlencode($reference), [], 'GET');

            if (!$response['success']) {
                return ['success' => false, 'message' => 'Payment verification failed'];
            }

            $data = $response['data'];

            // Check if payment was successful
            if ($data['status'] !== 'success') {
                $this->db->update('deposit_transactions',
                    ['status' => 'failed'],
                    'reference = ?',
                    [$reference]
                );
                return ['success' => false, 'message' => 'Payment was not successful'];
            }

            // Get transaction from database
            $transaction = $this->db->fetch(
                "SELECT * FROM deposit_transactions WHERE reference = ?",
                [$reference]
            );

            if (!$transaction) {
                return ['success' => false, 'message' => 'Transaction not found'];
            }

            if ($transaction['status'] === 'completed') {
                return ['success' => false, 'message' => 'Transaction already processed'];
            }

            // Verify amount matches
            if ($data['amount'] !== $transaction['amount'] * 100) {
                $this->db->update('deposit_transactions',
                    ['status' => 'failed', 'reason' => 'Amount mismatch'],
                    'reference = ?',
                    [$reference]
                );
                return ['success' => false, 'message' => 'Amount mismatch in payment'];
            }

            // Update transaction status
            $this->db->update('deposit_transactions',
                [
                    'status' => 'completed',
                    'transaction_id' => $data['id'],
                    'completed_at' => date('Y-m-d H:i:s')
                ],
                'reference = ?',
                [$reference]
            );

            // Credit user account
            $user = $this->db->fetch("SELECT balance FROM users WHERE id = ?", [$transaction['user_id']]);
            $new_balance = $user['balance'] + $transaction['amount'];

            $this->db->update('users',
                ['balance' => $new_balance],
                'id = ?',
                [$transaction['user_id']]
            );

            // Log transaction
            $this->db->insert('transactions', [
                'user_id' => $transaction['user_id'],
                'type' => 'deposit',
                'amount' => $transaction['amount'],
                'description' => 'Paystack deposit - Ref: ' . $reference,
                'created_at' => date('Y-m-d H:i:s')
            ]);

            return [
                'success' => true,
                'message' => 'Payment verified successfully',
                'amount' => $transaction['amount'],
                'user_id' => $transaction['user_id'],
                'new_balance' => $new_balance
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Create payment plan (subscription)
     */
    public function createPaymentPlan($interval, $amount, $name, $description) {
        try {
            $response = $this->callPaystackAPI('/plan', [
                'interval' => $interval, // monthly, quarterly, biannually, annually
                'amount' => $amount * 100,
                'name' => $name,
                'description' => $description,
                'currency' => 'USD'
            ]);

            return $response;
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get payment methods
     */
    public function getPaymentMethods() {
        return [
            [
                'name' => 'Card',
                'code' => 'card',
                'icon' => 'fa-credit-card'
            ],
            [
                'name' => 'Bank Transfer',
                'code' => 'bank',
                'icon' => 'fa-university'
            ],
            [
                'name' => 'Mobile Money',
                'code' => 'ussd',
                'icon' => 'fa-mobile'
            ],
            [
                'name' => 'Bank Account',
                'code' => 'account',
                'icon' => 'fa-money'
            ]
        ];
    }

    /**
     * Get user deposit history
     */
    public function getDepositHistory($user_id, $limit = 50) {
        return $this->db->fetchAll(
            "SELECT * FROM deposit_transactions
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT ?",
            [$user_id, $limit]
        );
    }

    /**
     * Get deposit statistics
     */
    public function getDepositStats($user_id) {
        $stats = $this->db->fetch(
            "SELECT
                COUNT(*) as total_deposits,
                SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as total_deposited,
                SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) as pending_amount,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count
             FROM deposit_transactions
             WHERE user_id = ?",
            [$user_id]
        );

        return $stats ?? [\n            'total_deposits' => 0,\n            'total_deposited' => 0,\n            'pending_amount' => 0,\n            'failed_count' => 0\n        ];
    }

    /**
     * Call Paystack API
     */
    private function callPaystackAPI($endpoint, $data = [], $method = 'POST') {
        $curl = curl_init();

        $headers = [
            'Authorization: Bearer ' . $this->secret_key,
            'Content-Type: application/json'
        ];

        $url = $this->base_url . $endpoint;

        if ($method === 'GET') {
            $url .= '?' . http_build_query($data);
        }

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $method !== 'GET' ? json_encode($data) : null,
        ]);

        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            throw new Exception("cURL Error #:" . $err);
        }

        return json_decode($response, true);
    }
}
?>
