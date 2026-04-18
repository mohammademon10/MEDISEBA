<?php
/**
 * MediSeba - Database Configuration
 * 
 * Production-ready database connection handler with PDO
 * Supports connection pooling, prepared statements, and error handling
 */

declare(strict_types=1);

namespace MediSeba\Config;

use PDO;
use PDOException;
use Exception;

class Database
{
    private static ?PDO $instance = null;
    private static array $config = [];
    
    /**
     * Initialize database configuration
     */
    public static function init(array $config): void
    {
        self::$config = array_merge([
            'host' => 'localhost',
            'port' => 3306,
            'database' => '',
            'username' => '',
            'password' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'timezone' => 'UTC',
            'persistent' => true,
            'debug' => false
        ], $config);
    }
    
    /**
     * Get PDO database connection instance (Singleton)
     */
    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            self::createConnection();
        }
        
        return self::$instance;
    }
    
    /**
     * Create new database connection
     */
    private static function createConnection(): void
    {
        if (empty(self::$config)) {
            throw new Exception('Database configuration not initialized. Call Database::init() first.');
        }

        if (
            trim((string) self::$config['database']) === '' ||
            trim((string) self::$config['username']) === ''
        ) {
            throw new Exception('Database configuration is incomplete. Please verify the deployment environment variables.');
        }
        
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            self::$config['host'],
            self::$config['port'],
            self::$config['database'],
            self::$config['charset']
        );
        
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => sprintf(
                "SET NAMES %s COLLATE %s",
                self::$config['charset'],
                self::$config['collation']
            )
        ];
        
        if (self::$config['persistent']) {
            $options[PDO::ATTR_PERSISTENT] = true;
        }
        
        try {
            self::$instance = new PDO(
                $dsn,
                self::$config['username'],
                self::$config['password'],
                $options
            );

            self::applySessionConfiguration();
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            throw new Exception('Database connection failed. Please try again later.');
        }
    }

    /**
     * Apply connection-level settings after the PDO handle is created.
     */
    private static function applySessionConfiguration(): void
    {
        if (self::$instance === null) {
            return;
        }

        $offset = self::resolveMysqlTimezoneOffset((string) (self::$config['timezone'] ?? 'UTC'));
        self::$instance->exec("SET time_zone = " . self::$instance->quote($offset));
    }

    /**
     * Convert a PHP timezone identifier to a MySQL-compatible UTC offset.
     */
    private static function resolveMysqlTimezoneOffset(string $timezone): string
    {
        try {
            $dateTime = new \DateTimeImmutable('now', new \DateTimeZone($timezone));
            return $dateTime->format('P');
        } catch (\Throwable $e) {
            return '+00:00';
        }
    }
    
    /**
     * Close database connection
     */
    public static function closeConnection(): void
    {
        self::$instance = null;
    }
    
    /**
     * Check if connection is alive
     */
    public static function isConnected(): bool
    {
        try {
            if (self::$instance === null) {
                return false;
            }
            self::$instance->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * Reconnect to database if connection lost
     */
    public static function reconnect(): PDO
    {
        self::closeConnection();
        return self::getConnection();
    }
    
    /**
     * Begin transaction
     */
    public static function beginTransaction(): bool
    {
        return self::getConnection()->beginTransaction();
    }
    
    /**
     * Commit transaction
     */
    public static function commit(): bool
    {
        return self::getConnection()->commit();
    }
    
    /**
     * Rollback transaction
     */
    public static function rollback(): bool
    {
        return self::getConnection()->rollBack();
    }
    
    /**
     * Check if in transaction
     */
    public static function inTransaction(): bool
    {
        return self::getConnection()->inTransaction();
    }
    
    /**
     * Execute raw query (use with caution)
     */
    public static function rawQuery(string $sql, array $params = []): array
    {
        $stmt = self::getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    /**
     * Get last insert ID
     */
    public static function lastInsertId(): string
    {
        return self::getConnection()->lastInsertId();
    }
    
    /**
     * Prevent cloning
     */
    private function __clone() {}
}
