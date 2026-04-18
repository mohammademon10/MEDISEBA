<?php
/**
 * MediSeba - Environment Configuration
 * 
 * Centralized configuration management
 * Loads from environment variables with fallback defaults
 */

declare(strict_types=1);

namespace MediSeba\Config;

class Environment
{
    private static array $config = [];
    private static bool $loaded = false;
    
    /**
     * Load environment configuration
     */
    public static function load(string $envFile = __DIR__ . '/../../.env'): void
    {
        if (self::$loaded) {
            return;
        }
        
        // Load from .env file if exists
        if (file_exists($envFile)) {
            self::loadEnvFile($envFile);
        }
        
        // Set default configurations
        self::$config = array_merge(self::getDefaults(), self::$config);
        self::$loaded = true;
        
        // Initialize database with config
        Database::init(self::getDatabaseConfig());
    }
    
    /**
     * Load environment variables from file
     */
    private static function loadEnvFile(string $file): void
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            // Skip comments
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            
            // Parse key=value pairs
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                // Remove quotes if present
                if ((strpos($value, '"') === 0 && strrpos($value, '"') === strlen($value) - 1) ||
                    (strpos($value, "'") === 0 && strrpos($value, "'") === strlen($value) - 1)) {
                    $value = substr($value, 1, -1);
                }
                
                self::$config[$key] = $value;
                $_ENV[$key] = $value;
            }
        }
    }
    
    /**
     * Get default configuration values
     */
    private static function getDefaults(): array
    {
        return [
            // Application
            'APP_NAME' => 'MediSeba',
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => '',
            'APP_TIMEZONE' => 'Asia/Dhaka',
            
            // Database
            'DB_HOST' => 'localhost',
            'DB_PORT' => '3306',
            'DB_DATABASE' => '',
            'DB_USERNAME' => '',
            'DB_PASSWORD' => '',
            'DB_CHARSET' => 'utf8mb4',
            
            // Security
            'JWT_SECRET' => '',
            'JWT_EXPIRY' => '86400',
            'SESSION_LIFETIME' => '86400',
            'CSRF_TOKEN_LIFETIME' => '3600',
            
            // OTP
            'OTP_EXPIRY_MINUTES' => '5',
            'OTP_MAX_ATTEMPTS' => '3',
            'OTP_RATE_LIMIT_PER_HOUR' => '5',

            // Email OTP
            'EMAILJS_PUBLIC_KEY' => '',
            'EMAILJS_PRIVATE_KEY' => '',
            'EMAILJS_SERVICE_ID' => '',
            'EMAILJS_TEMPLATE_ID' => '',
            'OTP_DELIVERY_MODE' => 'server_emailjs',
            
            // Rate Limiting
            'RATE_LIMIT_ENABLED' => 'true',
            'RATE_LIMIT_OTP' => '5',
            'RATE_LIMIT_LOGIN' => '5',
            'RATE_LIMIT_API' => '100',
            
            // File Upload
            'MAX_UPLOAD_SIZE' => '5242880',
            'ALLOWED_IMAGE_TYPES' => 'jpg,jpeg,png',
            'UPLOAD_PATH' => '/uploads',
            
            // Payment
            'PAYMENT_GATEWAY' => 'sandbox',
            'PAYMENT_CURRENCY' => 'BDT',
            'PAYMENT_GATEWAY_SECRET' => '',
            
            // CORS
            'CORS_ALLOWED_ORIGINS' => '*',
            'CORS_ALLOWED_METHODS' => 'GET,POST,PUT,PATCH,DELETE,OPTIONS',
            'CORS_ALLOWED_HEADERS' => 'Content-Type,Authorization,X-Authorization,X-Auth-Token,X-Requested-With,X-CSRF-Token',
        ];
    }
    
    /**
     * Get configuration value
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$config[$key] ?? $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }
    
    /**
     * Get database configuration array
     */
    public static function getDatabaseConfig(): array
    {
        return [
            'host' => self::get('DB_HOST'),
            'port' => (int) self::get('DB_PORT'),
            'database' => self::get('DB_DATABASE'),
            'username' => self::get('DB_USERNAME'),
            'password' => self::get('DB_PASSWORD'),
            'charset' => self::get('DB_CHARSET'),
            'timezone' => self::get('APP_TIMEZONE'),
            'debug' => self::get('APP_DEBUG') === 'true'
        ];
    }
    
    /**
     * Check if running in production
     */
    public static function isProduction(): bool
    {
        return self::get('APP_ENV') === 'production';
    }
    
    /**
     * Check if debug mode enabled
     */
    public static function isDebug(): bool
    {
        return self::get('APP_DEBUG') === 'true';
    }
    
    /**
     * Get application URL
     */
    public static function getAppUrl(): string
    {
        return rtrim(self::get('APP_URL'), '/');
    }
    
    /**
     * Set configuration value at runtime
     */
    public static function set(string $key, mixed $value): void
    {
        self::$config[$key] = $value;
    }
}
