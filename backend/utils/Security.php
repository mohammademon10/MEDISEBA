<?php
/**
 * MediSeba - Security Utilities
 * 
 * Comprehensive security functions for:
 * - Input sanitization
 * - Output encoding
 * - Password hashing
 * - OTP generation and verification
 * - CSRF protection
 * - Rate limiting
 */

declare(strict_types=1);

namespace MediSeba\Utils;

use MediSeba\Config\Environment;

class Security
{
    private static array $csrfTokens = [];
    
    /**
     * Generate cryptographically secure random string
     */
    public static function generateRandomString(int $length = 32): string
    {
        return bin2hex(random_bytes($length / 2));
    }
    
    /**
     * Generate 6-digit OTP
     */
    public static function generateOTP(): string
    {
        return str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    }
    
    /**
     * Hash OTP for database storage
     */
    public static function hashOTP(string $otp): string
    {
        return password_hash($otp, PASSWORD_BCRYPT, ['cost' => 10]);
    }
    
    /**
     * Verify OTP against hash
     */
    public static function verifyOTP(string $otp, string $hash): bool
    {
        return password_verify($otp, $hash);
    }
    
    /**
     * Hash password securely
     */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 3
        ]);
    }
    
    /**
     * Verify password
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
    
    /**
     * Minimum required length for the JWT secret key.
     * HMAC-SHA256 should use a key of at least 256 bits (32 bytes).
     */
    private const JWT_SECRET_MIN_LENGTH = 32;
    
    /**
     * Retrieve and validate the JWT signing secret.
     *
     * @throws \RuntimeException if the secret is missing or too short
     */
    private static function getJwtSecret(): string
    {
        $secret = Environment::get('JWT_SECRET', '');
        
        if (empty($secret) || strlen($secret) < self::JWT_SECRET_MIN_LENGTH) {
            error_log(
                'SECURITY FATAL: JWT_SECRET is missing or too short '
                . '(minimum ' . self::JWT_SECRET_MIN_LENGTH . ' characters required). '
                . 'Generate one with: openssl rand -hex 64'
            );
            throw new \RuntimeException(
                'JWT signing key is not configured. '
                . 'Set a strong JWT_SECRET in your .env file (minimum '
                . self::JWT_SECRET_MIN_LENGTH . ' characters).'
            );
        }
        
        return $secret;
    }
    
    /**
     * Generate JWT token
     */
    public static function generateJWT(array $payload, int $expiry = null): string
    {
        $secret = self::getJwtSecret();
        $expiry = $expiry ?? (int) Environment::get('JWT_EXPIRY', 86400);
        
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $time = time();
        $payload['iat'] = $time;
        $payload['exp'] = $time + $expiry;
        
        $payloadEncoded = json_encode($payload);
        
        $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64Payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payloadEncoded));
        
        $signature = hash_hmac('sha256', $base64Header . "." . $base64Payload, $secret, true);
        $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        
        return $base64Header . "." . $base64Payload . "." . $base64Signature;
    }
    
    /**
     * Verify and decode JWT token
     */
    public static function verifyJWT(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        
        [$base64Header, $base64Payload, $base64Signature] = $parts;
        
        // Verify signature using the validated secret
        $secret = self::getJwtSecret();
        $signature = hash_hmac('sha256', $base64Header . "." . $base64Payload, $secret, true);
        $expectedSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        
        if (!hash_equals($expectedSignature, $base64Signature)) {
            return null;
        }
        
        // Decode payload
        $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $base64Payload)), true);
        
        // Check expiration
        if (!isset($payload['exp']) || $payload['exp'] < time()) {
            return null;
        }
        
        return $payload;
    }
    
    /**
     * Sanitize input string
     */
    public static function sanitize(string $input): string
    {
        $input = trim($input);
        $input = stripslashes($input);
        $input = htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return $input;
    }
    
    /**
     * Sanitize email address
     */
    public static function sanitizeEmail(string $email): string
    {
        return filter_var(trim($email), FILTER_SANITIZE_EMAIL);
    }
    
    /**
     * Encode output for HTML
     */
    public static function encodeHtml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    /**
     * Generate CSRF token
     */
    public static function generateCsrfToken(): string
    {
        $token = self::generateRandomString(32);
        $_SESSION['csrf_token'] = $token;
        $_SESSION['csrf_token_time'] = time();
        return $token;
    }
    
    /**
     * Validate CSRF token
     */
    public static function validateCsrfToken(string $token): bool
    {
        if (empty($_SESSION['csrf_token']) || empty($_SESSION['csrf_token_time'])) {
            return false;
        }
        
        $lifetime = (int) Environment::get('CSRF_TOKEN_LIFETIME', 3600);
        if (time() - $_SESSION['csrf_token_time'] > $lifetime) {
            unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
            return false;
        }
        
        return hash_equals($_SESSION['csrf_token'], $token);
    }
    
    /**
     * Get current CSRF token
     */
    public static function getCsrfToken(): ?string
    {
        return $_SESSION['csrf_token'] ?? null;
    }
    
    /**
     * Generate secure session ID
     */
    public static function regenerateSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
    
    /**
     * Secure session initialization
     */
    public static function initSecureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_secure', self::isHttpsRequest() ? '1' : '0');
            ini_set('session.cookie_samesite', 'Strict');
            ini_set('session.use_strict_mode', '1');
            ini_set('session.gc_maxlifetime', (string) (int) Environment::get('SESSION_LIFETIME', 86400));
            
            session_start();
        }
    }

    private static function isHttpsRequest(): bool
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        if ($https === 'on' || $https === '1') {
            return true;
        }

        $forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($forwardedProto === 'https') {
            return true;
        }

        $appUrl = strtolower((string) Environment::get('APP_URL', ''));
        return str_starts_with($appUrl, 'https://');
    }
    
    /**
     * Validate phone number format (Bangladesh)
     */
    public static function validatePhone(string $phone): bool
    {
        // Support +8801XXXXXXXXX or 01XXXXXXXXX format
        $pattern = '/^(\+8801|01)[3-9]\d{8}$/';
        return preg_match($pattern, $phone) === 1;
    }
    
    /**
     * Normalize phone number to international format
     */
    public static function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        
        if (strpos($phone, '0') === 0 && strlen($phone) === 11) {
            $phone = '+88' . $phone;
        }
        
        return $phone;
    }
    
    /**
     * Get client IP address
     */
    public static function getClientIp(): string
    {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return '0.0.0.0';
    }
    
    /**
     * Get user agent string
     */
    public static function getUserAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    }
    
    /**
     * Encrypt sensitive data
     */
    public static function encrypt(string $data, string $key = null): string
    {
        $key = $key ?? Environment::get('JWT_SECRET');
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($data, 'AES-256-GCM', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $encrypted);
    }
    
    /**
     * Decrypt sensitive data
     */
    public static function decrypt(string $data, string $key = null): ?string
    {
        try {
            $key = $key ?? Environment::get('JWT_SECRET');
            $data = base64_decode($data);
            $iv = substr($data, 0, 16);
            $tag = substr($data, 16, 16);
            $encrypted = substr($data, 32);
            $decrypted = openssl_decrypt($encrypted, 'AES-256-GCM', $key, OPENSSL_RAW_DATA, $iv, $tag);
            return $decrypted !== false ? $decrypted : null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Generate secure appointment number
     */
    public static function generateAppointmentNumber(): string
    {
        return 'APT-' . date('Ymd') . '-' . strtoupper(self::generateRandomString(6));
    }
    
    /**
     * Generate secure payment number
     */
    public static function generatePaymentNumber(): string
    {
        return 'PAY-' . date('Ymd') . '-' . strtoupper(self::generateRandomString(6));
    }
    
    /**
     * Generate secure prescription number
     */
    public static function generatePrescriptionNumber(): string
    {
        return 'RX-' . date('Ymd') . '-' . strtoupper(self::generateRandomString(6));
    }
}
