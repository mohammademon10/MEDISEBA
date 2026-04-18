<?php
/**
 * MediSeba - Authentication Middleware
 * 
 * Handles JWT token validation and user authentication
 */

declare(strict_types=1);

namespace MediSeba\Middleware;

use MediSeba\Utils\Security;
use MediSeba\Utils\Response;
use MediSeba\Models\User;

class AuthMiddleware
{
    public static function authenticate(): ?array
    {
        // Get authorization header
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        
        // Check if header starts with "Bearer "
        if (!str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }
        
        // Extract token
        $token = substr($authHeader, 7);
        
        if (empty($token)) {
            return null;
        }
        
        // Verify JWT token
        $payload = Security::verifyJWT($token);
        
        if (!$payload) {
            return null;
        }
        
        // Verify user exists and is active
        $userModel = new User();
        $user = $userModel->find($payload['user_id']);
        
        if (!$user || $user['status'] !== 'active') {
            return null;
        }
        
        return [
            'user_id' => $payload['user_id'],
            'email' => $payload['email'] ?? null,
            'role' => $payload['role']
        ];
    }
    
    /**
     * Require authentication
     */
    public static function requireAuth(): array
    {
        $user = self::authenticate();
        
        if (!$user) {
            Response::unauthorized('Authentication required. Please provide a valid token.');
        }
        
        return $user;
    }
    
    /**
     * Require specific role(s)
     */
    public static function requireRole(array|string $roles): array
    {
        $user = self::requireAuth();
        
        if (is_string($roles)) {
            $roles = [$roles];
        }
        
        if (!in_array($user['role'], $roles, true)) {
            Response::forbidden('You do not have permission to access this resource');
        }
        
        return $user;
    }
    
    /**
     * Check if user has role (doesn't require auth)
     */
    public static function hasRole(array|string $roles): bool
    {
        $user = self::authenticate();
        
        if (!$user) {
            return false;
        }
        
        if (is_string($roles)) {
            $roles = [$roles];
        }
        
        return in_array($user['role'], $roles, true);
    }
    
    /**
     * Optional authentication - returns user if authenticated, null otherwise
     */
    public static function optionalAuth(): ?array
    {
        return self::authenticate();
    }
    
    /**
     * Require admin role
     */
    public static function requireAdmin(): array
    {
        return self::requireRole('admin');
    }
    
    /**
     * Require doctor role
     */
    public static function requireDoctor(): array
    {
        return self::requireRole('doctor');
    }
    
    /**
     * Require patient role
     */
    public static function requirePatient(): array
    {
        return self::requireRole('patient');
    }
    
    /**
     * Require doctor or admin role
     */
    public static function requireDoctorOrAdmin(): array
    {
        return self::requireRole(['doctor', 'admin']);
    }
    
    /**
     * Require patient or admin role
     */
    public static function requirePatientOrAdmin(): array
    {
        return self::requireRole(['patient', 'admin']);
    }
}
