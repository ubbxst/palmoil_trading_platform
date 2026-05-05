-- Add Deposit Tables to existing schema

-- Deposit Transactions Table
CREATE TABLE IF NOT EXISTS deposit_transactions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    reference VARCHAR(255) UNIQUE NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    email VARCHAR(255) NOT NULL,
    transaction_id VARCHAR(255),
    status ENUM('pending', 'completed', 'failed') DEFAULT 'pending',
    reason VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_user (user_id),
    INDEX idx_reference (reference),
    INDEX idx_status (status),
    INDEX idx_created (created_at)
);

-- Update transactions table to include deposit type
ALTER TABLE transactions MODIFY type ENUM('buy', 'sell', 'deposit', 'withdrawal') NOT NULL;

-- Add amount field to transactions if not exists
ALTER TABLE transactions ADD COLUMN amount DECIMAL(15, 2) DEFAULT NULL AFTER type;

-- Add description field to transactions if not exists
ALTER TABLE transactions ADD COLUMN description TEXT DEFAULT NULL AFTER amount;

-- Create index for faster queries
CREATE INDEX idx_transaction_type ON transactions(type);
CREATE INDEX idx_transaction_user ON transactions(user_id);
