<?php
require_once CORE_PATH . '/Model.php';

/**
 * RememberToken Model
 * 
 * Handles secure persistent authentication tokens for "Keep me signed in" functionality.
 * 
 * Security Implementation:
 * - Uses split-token approach: selector (public) + validator (hashed)
 * - Tokens are cryptographically secure (random_bytes)
 * - Validators are hashed with PASSWORD_DEFAULT before storage
 * - Tokens expire after 90 days
 * - Stores user agent and IP for additional security
 * - Automatic cleanup of expired tokens
 */
class RememberTokenModel extends Model
{
    protected string $table = 'remember_tokens';
    
    /**
     * Token Configuration
     */
    private const SELECTOR_BYTES = 16;  // 16 bytes = 128 bits for selector
    private const VALIDATOR_BYTES = 32; // 32 bytes = 256 bits for validator
    private const TOKEN_LIFETIME_DAYS = 90; // Token expires after 90 days
    
    /**
     * Create a new remember token for a user
     * 
     * @param int $userId The user ID to create token for
     * @return array ['selector' => string, 'validator' => string, 'cookie_value' => string]
     */
    public function createToken(int $userId): array
    {
        // Generate cryptographically secure random tokens
        $selector = bin2hex(random_bytes(self::SELECTOR_BYTES));
        $validator = bin2hex(random_bytes(self::VALIDATOR_BYTES));
        
        // Hash the validator before storing (never store plaintext)
        $validatorHash = password_hash($validator, PASSWORD_DEFAULT);
        
        // Calculate expiry (90 days from now)
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . self::TOKEN_LIFETIME_DAYS . ' days'));
        
        // Get client information for security
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        
        // Insert token into database
        $stmt = $this->db->prepare("
            INSERT INTO remember_tokens 
            (user_id, token_selector, token_hash, expires_at, user_agent, ip_address, created_at)
            VALUES (:user_id, :selector, :hash, :expires_at, :user_agent, :ip_address, NOW())
        ");
        
        $stmt->execute([
            ':user_id' => $userId,
            ':selector' => $selector,
            ':hash' => $validatorHash,
            ':expires_at' => $expiresAt,
            ':user_agent' => $userAgent,
            ':ip_address' => $ipAddress,
        ]);
        
        // Return both parts for cookie creation
        // Format: selector:validator (combined for cookie storage)
        return [
            'selector' => $selector,
            'validator' => $validator,
            'cookie_value' => $selector . ':' . $validator,
            'expires_at' => $expiresAt,
        ];
    }
    
    /**
     * Validate a remember token and return user ID if valid
     * 
     * @param string $cookieValue The cookie value (selector:validator)
     * @return int|null User ID if valid, null if invalid/expired
     */
    public function validateToken(string $cookieValue): ?int
    {
        // Clean up expired tokens first
        $this->cleanupExpired();
        
        // Split cookie value into selector and validator
        $parts = explode(':', $cookieValue, 2);
        if (count($parts) !== 2) {
            return null; // Invalid format
        }
        
        [$selector, $validator] = $parts;
        
        // Find token by selector
        $stmt = $this->db->prepare("
            SELECT id, user_id, token_hash, expires_at, user_agent
            FROM remember_tokens
            WHERE token_selector = :selector
            AND expires_at > NOW()
            LIMIT 1
        ");
        
        $stmt->execute([':selector' => $selector]);
        $token = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$token) {
            return null; // Token not found or expired
        }
        
        // Verify the validator hash
        if (!password_verify($validator, $token['token_hash'])) {
            // Invalid validator - possible token theft attempt
            // Delete this token for security
            $this->revokeToken($token['id']);
            return null;
        }
        
        // Optional: Check user agent hasn't changed (additional security)
        $currentUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        if ($token['user_agent'] !== null && $token['user_agent'] !== $currentUserAgent) {
            // User agent changed - possible session hijacking
            // You may choose to accept or reject based on security policy
            // For now, we'll log but accept (user might have updated browser)
            error_log("Remember token user agent mismatch for user {$token['user_id']}");
        }
        
        // Update last_used_at timestamp
        $this->updateLastUsed($token['id']);
        
        return (int)$token['user_id'];
    }
    
    /**
     * Update the last_used_at timestamp for a token
     * 
     * @param int $tokenId The token ID
     */
    private function updateLastUsed(int $tokenId): void
    {
        $stmt = $this->db->prepare("
            UPDATE remember_tokens
            SET last_used_at = NOW()
            WHERE id = :id
        ");
        
        $stmt->execute([':id' => $tokenId]);
    }
    
    /**
     * Revoke a specific token by ID
     * 
     * @param int $tokenId The token ID to revoke
     */
    public function revokeToken(int $tokenId): void
    {
        $stmt = $this->db->prepare("
            DELETE FROM remember_tokens
            WHERE id = :id
        ");
        
        $stmt->execute([':id' => $tokenId]);
    }
    
    /**
     * Revoke all tokens for a specific user
     * 
     * @param int $userId The user ID
     */
    public function revokeAllUserTokens(int $userId): void
    {
        $stmt = $this->db->prepare("
            DELETE FROM remember_tokens
            WHERE user_id = :user_id
        ");
        
        $stmt->execute([':user_id' => $userId]);
    }
    
    /**
     * Revoke token by selector (useful when only have cookie value)
     * 
     * @param string $selector The token selector
     */
    public function revokeBySelector(string $selector): void
    {
        $stmt = $this->db->prepare("
            DELETE FROM remember_tokens
            WHERE token_selector = :selector
        ");
        
        $stmt->execute([':selector' => $selector]);
    }
    
    /**
     * Clean up expired tokens
     * Should be called periodically (or on every login attempt)
     */
    public function cleanupExpired(): void
    {
        $stmt = $this->db->prepare("
            DELETE FROM remember_tokens
            WHERE expires_at < NOW()
        ");
        
        $stmt->execute();
    }
    
    /**
     * Get all active tokens for a user (for security audit page)
     * 
     * @param int $userId The user ID
     * @return array List of active tokens with metadata
     */
    public function getUserTokens(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                id,
                user_agent,
                ip_address,
                created_at,
                last_used_at,
                expires_at
            FROM remember_tokens
            WHERE user_id = :user_id
            AND expires_at > NOW()
            ORDER BY created_at DESC
        ");
        
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Count active tokens for a user
     * 
     * @param int $userId The user ID
     * @return int Number of active tokens
     */
    public function countUserTokens(int $userId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) 
            FROM remember_tokens
            WHERE user_id = :user_id
            AND expires_at > NOW()
        ");
        
        $stmt->execute([':user_id' => $userId]);
        return (int)$stmt->fetchColumn();
    }
    
    /**
     * Rotate a token (create new one and delete old)
     * Used for enhanced security - issue new token on each use
     * 
     * @param string $oldSelector The old token selector
     * @param int $userId The user ID
     * @return array|null New token data or null if old token invalid
     */
    public function rotateToken(string $oldSelector, int $userId): ?array
    {
        // Verify old token exists and belongs to this user
        $stmt = $this->db->prepare("
            SELECT id FROM remember_tokens
            WHERE token_selector = :selector
            AND user_id = :user_id
            AND expires_at > NOW()
        ");
        
        $stmt->execute([
            ':selector' => $oldSelector,
            ':user_id' => $userId,
        ]);
        
        $oldToken = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$oldToken) {
            return null; // Old token invalid
        }
        
        // Create new token
        $newToken = $this->createToken($userId);
        
        // Delete old token
        $this->revokeToken($oldToken['id']);
        
        return $newToken;
    }
}
