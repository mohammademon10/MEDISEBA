<?php
/**
 * MediSeba - User Model
 * 
 * Handles user authentication and management
 */

declare(strict_types=1);

namespace MediSeba\Models;

use MediSeba\Utils\Security;

class User extends Model
{
    protected string $table = 'users';
    protected string $primaryKey = 'id';
    
    protected array $fillable = [
        'email',
        'password_hash',
        'role',
        'status',
        'email_verified_at',
        'last_login_at',
        'last_login_ip'
    ];
    
    protected array $hidden = ['password_hash'];
    
    protected array $casts = [
        'id' => 'int',
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];
    
    /**
     * Find user by email address
     */
    public function findByEmail(string $email): ?array
    {
        return $this->findBy('email', $email);
    }
    
    /**
     * Find user by email address for authentication (includes hidden fields like password_hash)
     */
    public function findByEmailForAuth(string $email): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $result = $stmt->fetch();
        
        return $result ? $result : null;
    }
    
    /**
     * Find or create user by email
     */
    public function findOrCreateByEmail(string $email, string $role = 'patient'): array
    {
        $user = $this->findByEmail($email);
        
        if ($user) {
            return $user;
        }
        
        $id = $this->create([
            'email' => filter_var($email, FILTER_SANITIZE_EMAIL),
            'role' => $role,
            'status' => 'active'
        ]);
        
        return $this->find($id);
    }
    
    /**
     * Update last login information
     */
    public function updateLastLogin(int $userId): bool
    {
        return $this->update($userId, [
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => Security::getClientIp()
        ]);
    }
    
    /**
     * Verify email address
     */
    public function verifyEmail(int $userId): bool
    {
        return $this->update($userId, [
            'email_verified_at' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Check if user has role
     */
    public function hasRole(int $userId, string|array $roles): bool
    {
        $user = $this->find($userId);
        
        if (!$user) {
            return false;
        }
        
        if (is_string($roles)) {
            $roles = [$roles];
        }
        
        return in_array($user['role'], $roles, true);
    }
    
    /**
     * Check if user is active
     */
    public function isActive(int $userId): bool
    {
        $user = $this->find($userId);
        return $user && $user['status'] === 'active';
    }
    
    /**
     * Get users by role
     */
    public function getByRole(string $role, int $page = 1, int $perPage = 20): array
    {
        $offset = ($page - 1) * $perPage;
        
        $sql = "SELECT * FROM {$this->table} WHERE role = ? ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$role, $perPage, $offset]);
        
        $results = $stmt->fetchAll();
        
        $countSql = "SELECT COUNT(*) FROM {$this->table} WHERE role = ?";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute([$role]);
        $total = (int) $countStmt->fetchColumn();
        
        return [
            'items' => array_map([$this, 'processResult'], $results),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage)
        ];
    }
    
    /**
     * Search users
     */
    public function search(string $query, ?string $role = null, int $limit = 20): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE (phone LIKE ? OR email LIKE ?)";
        $params = ["%{$query}%", "%{$query}%"];
        
        if ($role) {
            $sql .= " AND role = ?";
            $params[] = $role;
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return array_map([$this, 'processResult'], $stmt->fetchAll());
    }
    
    /**
     * Update user status
     */
    public function updateStatus(int $userId, string $status): bool
    {
        return $this->update($userId, ['status' => $status]);
    }
    
    /**
     * Update user role
     */
    public function updateRole(int $userId, string $role): bool
    {
        return $this->update($userId, ['role' => $role]);
    }
    
    /**
     * Get user statistics
     */
    public function getStatistics(): array
    {
        $sql = "SELECT 
            COUNT(*) as total_users,
            SUM(CASE WHEN role = 'patient' THEN 1 ELSE 0 END) as total_patients,
            SUM(CASE WHEN role = 'doctor' THEN 1 ELSE 0 END) as total_doctors,
            SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as total_admins,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_users,
            SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as new_today
        FROM {$this->table}";
        
        $stmt = $this->db->query($sql);
        return $stmt->fetch();
    }
}
