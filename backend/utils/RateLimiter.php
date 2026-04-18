<?php
/**
 * MediSeba - Rate Limiting Utility
 * 
 * Prevents abuse through rate limiting on OTP requests, logins, and API calls
 */

declare(strict_types=1);

namespace MediSeba\Utils;

use MediSeba\Config\Database;
use MediSeba\Config\Environment;

class RateLimiter
{
    private const TYPE_OTP = 'otp_request';
    private const TYPE_LOGIN = 'login_attempt';
    private const TYPE_API = 'api_call';
    
    /**
     * Check if rate limit is exceeded
     */
    public static function isExceeded(string $identifier, string $type, int $maxAttempts = null, int $windowSeconds = null): bool
    {
        if (Environment::get('RATE_LIMIT_ENABLED') !== 'true') {
            return false;
        }
        
        // Get limits based on type
        list($maxAttempts, $windowSeconds) = self::getLimits($type, $maxAttempts, $windowSeconds);
        
        $db = Database::getConnection();
        
        // Clean up expired entries
        self::cleanup($type);
        
        // Get current count
        $stmt = $db->prepare("SELECT count, reset_at FROM rate_limits WHERE identifier = ? AND type = ?");
        $stmt->execute([$identifier, $type]);
        $record = $stmt->fetch();
        
        if (!$record) {
            return false;
        }
        
        // Check if window has expired
        if (strtotime($record['reset_at']) < time()) {
            self::reset($identifier, $type);
            return false;
        }
        
        return (int) $record['count'] >= $maxAttempts;
    }
    
    /**
     * Increment rate limit counter
     */
    public static function increment(string $identifier, string $type, int $windowSeconds = null): void
    {
        if (Environment::get('RATE_LIMIT_ENABLED') !== 'true') {
            return;
        }
        
        list(, $windowSeconds) = self::getLimits($type, null, $windowSeconds);
        
        $db = Database::getConnection();
        $resetAt = date('Y-m-d H:i:s', time() + $windowSeconds);
        
        // Try to update existing record
        $stmt = $db->prepare("UPDATE rate_limits SET count = count + 1 WHERE identifier = ? AND type = ? AND reset_at > NOW()");
        $stmt->execute([$identifier, $type]);
        
        // If no rows updated, insert new record
        if ($stmt->rowCount() === 0) {
            $stmt = $db->prepare("INSERT INTO rate_limits (identifier, type, count, reset_at) VALUES (?, ?, 1, ?) ON DUPLICATE KEY UPDATE count = 1, reset_at = ?");
            $stmt->execute([$identifier, $type, $resetAt, $resetAt]);
        }
    }
    
    /**
     * Get remaining attempts
     */
    public static function getRemainingAttempts(string $identifier, string $type, int $maxAttempts = null): int
    {
        list($maxAttempts) = self::getLimits($type, $maxAttempts, null);
        
        $db = Database::getConnection();
        
        $stmt = $db->prepare("SELECT count FROM rate_limits WHERE identifier = ? AND type = ? AND reset_at > NOW()");
        $stmt->execute([$identifier, $type]);
        $record = $stmt->fetch();
        
        if (!$record) {
            return $maxAttempts;
        }
        
        return max(0, $maxAttempts - (int) $record['count']);
    }
    
    /**
     * Get time until reset
     */
    public static function getTimeUntilReset(string $identifier, string $type): int
    {
        $db = Database::getConnection();
        
        $stmt = $db->prepare("SELECT reset_at FROM rate_limits WHERE identifier = ? AND type = ?");
        $stmt->execute([$identifier, $type]);
        $record = $stmt->fetch();
        
        if (!$record) {
            return 0;
        }
        
        $resetTime = strtotime($record['reset_at']);
        return max(0, $resetTime - time());
    }
    
    /**
     * Reset rate limit for identifier
     */
    public static function reset(string $identifier, string $type): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM rate_limits WHERE identifier = ? AND type = ?");
        $stmt->execute([$identifier, $type]);
    }
    
    /**
     * Clean up expired rate limit entries
     */
    public static function cleanup(string $type = null): void
    {
        $db = Database::getConnection();
        
        if ($type) {
            $stmt = $db->prepare("DELETE FROM rate_limits WHERE type = ? AND reset_at < NOW()");
            $stmt->execute([$type]);
        } else {
            $stmt = $db->query("DELETE FROM rate_limits WHERE reset_at < NOW()");
        }
    }
    
    /**
     * Get rate limit limits based on type
     */
    private static function getLimits(string $type, ?int $maxAttempts, ?int $windowSeconds): array
    {
        if ($maxAttempts === null) {
            $maxAttempts = match ($type) {
                self::TYPE_OTP => (int) Environment::get('RATE_LIMIT_OTP', 5),
                self::TYPE_LOGIN => (int) Environment::get('RATE_LIMIT_LOGIN', 5),
                self::TYPE_API => (int) Environment::get('RATE_LIMIT_API', 100),
                default => 60
            };
        }
        
        if ($windowSeconds === null) {
            $windowSeconds = match ($type) {
                self::TYPE_OTP => 3600,  // 1 hour
                self::TYPE_LOGIN => 900,  // 15 minutes
                self::TYPE_API => 60,     // 1 minute
                default => 3600
            };
        }
        
        return [$maxAttempts, $windowSeconds];
    }
    
    /**
     * Check OTP rate limit
     */
    public static function checkOtpLimit(string $phone): array
    {
        $identifier = 'otp:' . $phone;
        
        if (self::isExceeded($identifier, self::TYPE_OTP)) {
            return [
                'allowed' => false,
                'retry_after' => self::getTimeUntilReset($identifier, self::TYPE_OTP),
                'message' => 'Too many OTP requests. Please try again later.'
            ];
        }
        
        return [
            'allowed' => true,
            'remaining' => self::getRemainingAttempts($identifier, self::TYPE_OTP)
        ];
    }
    
    /**
     * Record OTP request
     */
    public static function recordOtpRequest(string $phone): void
    {
        self::increment('otp:' . $phone, self::TYPE_OTP);
    }
    
    /**
     * Check login rate limit
     */
    public static function checkLoginLimit(string $identifier): array
    {
        $key = 'login:' . $identifier;
        
        if (self::isExceeded($key, self::TYPE_LOGIN)) {
            return [
                'allowed' => false,
                'retry_after' => self::getTimeUntilReset($key, self::TYPE_LOGIN),
                'message' => 'Too many login attempts. Please try again later.'
            ];
        }
        
        return [
            'allowed' => true,
            'remaining' => self::getRemainingAttempts($key, self::TYPE_LOGIN)
        ];
    }
    
    /**
     * Record login attempt
     */
    public static function recordLoginAttempt(string $identifier): void
    {
        self::increment('login:' . $identifier, self::TYPE_LOGIN);
    }
    
    /**
     * Clear login attempts on successful login
     */
    public static function clearLoginAttempts(string $identifier): void
    {
        self::reset('login:' . $identifier, self::TYPE_LOGIN);
    }
    
    /**
     * Check API rate limit
     */
    public static function checkApiLimit(string $apiKeyOrIp): array
    {
        $identifier = 'api:' . $apiKeyOrIp;
        
        if (self::isExceeded($identifier, self::TYPE_API)) {
            return [
                'allowed' => false,
                'retry_after' => self::getTimeUntilReset($identifier, self::TYPE_API),
                'message' => 'API rate limit exceeded. Please slow down.'
            ];
        }
        
        self::increment($identifier, self::TYPE_API);
        
        return [
            'allowed' => true,
            'remaining' => self::getRemainingAttempts($identifier, self::TYPE_API)
        ];
    }
}
