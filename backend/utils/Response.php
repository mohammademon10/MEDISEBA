<?php
/**
 * MediSeba - HTTP Response Handler
 * 
 * Standardized API response formatting with proper HTTP status codes
 */

declare(strict_types=1);

namespace MediSeba\Utils;

use MediSeba\Config\Environment;

class Response
{
    // HTTP Status Codes
    public const HTTP_OK = 200;
    public const HTTP_CREATED = 201;
    public const HTTP_ACCEPTED = 202;
    public const HTTP_NO_CONTENT = 204;
    public const HTTP_BAD_REQUEST = 400;
    public const HTTP_UNAUTHORIZED = 401;
    public const HTTP_FORBIDDEN = 403;
    public const HTTP_NOT_FOUND = 404;
    public const HTTP_METHOD_NOT_ALLOWED = 405;
    public const HTTP_CONFLICT = 409;
    public const HTTP_UNPROCESSABLE = 422;
    public const HTTP_TOO_MANY_REQUESTS = 429;
    public const HTTP_INTERNAL_ERROR = 500;
    public const HTTP_SERVICE_UNAVAILABLE = 503;
    
    /**
     * Send JSON response
     */
    public static function json(array $data, int $statusCode = self::HTTP_OK, array $headers = []): void
    {
        self::setHeaders($statusCode, $headers);
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    
    /**
     * Success response
     */
    public static function success(string $message = 'Success', array $data = [], int $statusCode = self::HTTP_OK): void
    {
        self::json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => date('c')
        ], $statusCode);
    }
    
    /**
     * Created response
     */
    public static function created(string $message = 'Resource created successfully', array $data = []): void
    {
        self::success($message, $data, self::HTTP_CREATED);
    }
    
    /**
     * Error response
     */
    public static function error(string $message = 'An error occurred', array $errors = [], int $statusCode = self::HTTP_BAD_REQUEST): void
    {
        self::json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
            'timestamp' => date('c')
        ], $statusCode);
    }
    
    /**
     * Validation error response
     */
    public static function validationError(array $errors): void
    {
        self::error('Validation failed', $errors, self::HTTP_UNPROCESSABLE);
    }
    
    /**
     * Not found response
     */
    public static function notFound(string $resource = 'Resource'): void
    {
        self::error("{$resource} not found", [], self::HTTP_NOT_FOUND);
    }
    
    /**
     * Unauthorized response
     */
    public static function unauthorized(string $message = 'Unauthorized'): void
    {
        self::error($message, [], self::HTTP_UNAUTHORIZED);
    }
    
    /**
     * Forbidden response
     */
    public static function forbidden(string $message = 'Access denied'): void
    {
        self::error($message, [], self::HTTP_FORBIDDEN);
    }
    
    /**
     * Too many requests response
     */
    public static function tooManyRequests(int $retryAfter = 60): void
    {
        self::json([
            'success' => false,
            'message' => 'Too many requests. Please try again later.',
            'errors' => [],
            'timestamp' => date('c')
        ], self::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => (string) $retryAfter]);
    }
    
    /**
     * Server error response
     */
    public static function serverError(string $message = 'Internal server error'): void
    {
        self::error($message, [], self::HTTP_INTERNAL_ERROR);
    }
    
    /**
     * Paginated response
     */
    public static function paginated(array $items, int $total, int $page, int $perPage, array $additionalData = []): void
    {
        $totalPages = (int) ceil($total / $perPage);
        
        self::success('Success', array_merge([
            'items' => $items,
            'pagination' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'total_pages' => $totalPages,
                'has_next_page' => $page < $totalPages,
                'has_prev_page' => $page > 1
            ]
        ], $additionalData));
    }
    
    /**
     * Set response headers
     */
    private static function setHeaders(int $statusCode, array $customHeaders = []): void
    {
        // Clear any previous output
        if (ob_get_level()) {
            ob_clean();
        }
        
        // Set HTTP status code
        http_response_code($statusCode);
        
        // Set default headers
        $headers = [
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'X-XSS-Protection' => '1; mode=block',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache'
        ];
        
        // Add CORS headers
        $headers = array_merge($headers, self::getCorsHeaders());
        
        // Merge custom headers
        $headers = array_merge($headers, $customHeaders);
        
        // Send headers
        foreach ($headers as $name => $value) {
            header("{$name}: {$value}");
        }
    }

    private static function getCorsHeaders(): array
    {
        $allowedOrigins = array_values(array_filter(array_map(
            static fn (string $origin): string => trim($origin),
            explode(',', (string) Environment::get('CORS_ALLOWED_ORIGINS', '*'))
        )));

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowOrigin = '*';
        $allowCredentials = 'false';

        if (!empty($allowedOrigins) && !in_array('*', $allowedOrigins, true)) {
            $allowOrigin = in_array($origin, $allowedOrigins, true)
                ? $origin
                : $allowedOrigins[0];
            $allowCredentials = 'true';
        }

        return [
            'Access-Control-Allow-Origin' => $allowOrigin,
            'Access-Control-Allow-Methods' => (string) Environment::get('CORS_ALLOWED_METHODS', 'GET,POST,PUT,PATCH,DELETE,OPTIONS'),
            'Access-Control-Allow-Headers' => (string) Environment::get('CORS_ALLOWED_HEADERS', 'Content-Type,Authorization,X-Authorization,X-Auth-Token,X-Requested-With,X-CSRF-Token'),
            'Access-Control-Allow-Credentials' => $allowCredentials
        ];
    }
    
    /**
     * Send raw response
     */
    public static function raw(string $content, string $contentType = 'text/plain', int $statusCode = self::HTTP_OK): void
    {
        http_response_code($statusCode);
        header("Content-Type: {$contentType}");
        echo $content;
        exit;
    }

    /**
     * Send a file download response
     */
    public static function download(
        string $content,
        string $filename,
        string $contentType = 'application/octet-stream',
        int $statusCode = self::HTTP_OK,
        array $headers = []
    ): void {
        $safeFilename = preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) ?: 'download.bin';

        self::setHeaders($statusCode, array_merge([
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="' . $safeFilename . '"',
            'Content-Length' => (string) strlen($content),
        ], $headers));

        echo $content;
        exit;
    }
    
    /**
     * Redirect response
     */
    public static function redirect(string $url, int $statusCode = 302): void
    {
        http_response_code($statusCode);
        header("Location: {$url}");
        exit;
    }
    
    /**
     * No content response
     */
    public static function noContent(): void
    {
        http_response_code(self::HTTP_NO_CONTENT);
        exit;
    }
    
    /**
     * Method not allowed response
     */
    public static function methodNotAllowed(array $allowedMethods = []): void
    {
        $headers = [];
        if (!empty($allowedMethods)) {
            $headers['Allow'] = implode(', ', $allowedMethods);
        }
        self::json([
            'success' => false,
            'message' => 'Method not allowed',
            'errors' => [],
            'timestamp' => date('c')
        ], self::HTTP_METHOD_NOT_ALLOWED, $headers);
    }
    
    /**
     * Conflict response
     */
    public static function conflict(string $message = 'Resource already exists'): void
    {
        self::error($message, [], self::HTTP_CONFLICT);
    }
}
