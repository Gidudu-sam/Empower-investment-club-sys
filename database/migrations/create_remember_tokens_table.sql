-- Migration: Create remember_tokens table for persistent authentication
-- Purpose: Store secure tokens for "Keep me signed in" functionality
-- Security: Tokens are hashed, expire after 90 days, tied to user agent

CREATE TABLE IF NOT EXISTS remember_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_selector VARCHAR(64) NOT NULL COMMENT 'Public token identifier (unhashed)',
    token_hash VARCHAR(255) NOT NULL COMMENT 'Hashed token validator for security',
    expires_at DATETIME NOT NULL COMMENT 'Token expiration (90 days from creation)',
    user_agent VARCHAR(255) DEFAULT NULL COMMENT 'Browser fingerprint for security',
    ip_address VARCHAR(45) DEFAULT NULL COMMENT 'IP address at token creation',
    last_used_at DATETIME DEFAULT NULL COMMENT 'Last time this token was used for auto-login',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_user_id (user_id),
    INDEX idx_token_selector (token_selector),
    INDEX idx_expires_at (expires_at),
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Persistent authentication tokens for Remember Me functionality';

-- Cleanup procedure: Remove expired tokens (run periodically)
-- This can be called from a cron job or on login
DELIMITER $$
CREATE PROCEDURE IF NOT EXISTS cleanup_expired_remember_tokens()
BEGIN
    DELETE FROM remember_tokens WHERE expires_at < NOW();
END$$
DELIMITER ;
