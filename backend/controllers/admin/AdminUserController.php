<?php
/**
 * MediSeba - Admin User Controller
 * 
 * Manages all users from admin panel
 */

declare(strict_types=1);

namespace MediSeba\Controllers\Admin;

use MediSeba\Utils\Response;
use MediSeba\Utils\Validator;
use MediSeba\Config\Database;

class AdminUserController
{
    /**
     * GET /api/admin/users
     */
    public function index(array $user, array $request): void
    {
        $db = Database::getConnection();
        $page = max(1, (int) ($request['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($request['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;
        $role = $request['role'] ?? '';
        $status = $request['status'] ?? '';
        $search = trim($request['search'] ?? '');

        $where = ["u.status != 'deleted'"];
        $params = [];

        if (in_array($role, ['patient', 'doctor', 'admin'], true)) {
            $where[] = 'u.role = ?';
            $params[] = $role;
        }

        if (in_array($status, ['active', 'inactive', 'suspended'], true)) {
            $where[] = 'u.status = ?';
            $params[] = $status;
        }

        if ($search !== '') {
            $where[] = '(u.email LIKE ? OR u.phone LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $whereClause = implode(' AND ', $where);

        // Count
        $countStmt = $db->prepare("SELECT COUNT(*) FROM users u WHERE {$whereClause}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Fetch
        $params[] = $perPage;
        $params[] = $offset;
        $stmt = $db->prepare("
            SELECT
                u.id AS user_id, u.id, u.email, u.phone, u.role, u.status,
                u.last_login_at, u.created_at, u.updated_at,
                COALESCE(pp.full_name, dp.full_name) AS full_name,
                COALESCE(pp.profile_photo, dp.profile_photo) AS profile_photo,
                pp.gender AS patient_gender,
                dp.specialty AS doctor_specialty,
                dp.is_verified AS is_doctor_verified
            FROM users u
            LEFT JOIN patient_profiles pp ON u.id = pp.user_id AND u.role = 'patient'
            LEFT JOIN doctor_profiles dp ON u.id = dp.user_id AND u.role = 'doctor'
            WHERE {$whereClause}
            ORDER BY u.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        $users = $stmt->fetchAll();

        Response::success('Users retrieved', [
            'items' => $users,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage)
        ]);
    }

    /**
     * PATCH /api/admin/users/{id}/status
     */
    public function updateStatus(array $user, int $id, array $request): void
    {
        $newStatus = $request['status'] ?? '';

        if (!in_array($newStatus, ['active', 'suspended', 'deleted'], true)) {
            Response::error('Invalid status. Use: active, suspended, or deleted');
        }

        // Prevent self-modification
        if ($id === (int) $user['user_id']) {
            Response::error('You cannot modify your own account status');
        }

        $db = Database::getConnection();

        // Verify user exists
        $stmt = $db->prepare("SELECT id, role, status FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $targetUser = $stmt->fetch();

        if (!$targetUser) {
            Response::notFound('User');
        }

        // Prevent modifying other admins (optional safeguard)
        if ($targetUser['role'] === 'admin' && $id !== (int) $user['user_id']) {
            Response::error('Cannot modify another admin account');
        }

        $updateStmt = $db->prepare("UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?");
        $updateStmt->execute([$newStatus, $id]);

        // Log activity
        $logStmt = $db->prepare("
            INSERT INTO activity_logs (user_id, action, entity_type, entity_id, ip_address, user_agent)
            VALUES (?, ?, 'user', ?, ?, ?)
        ");
        $action = match ($newStatus) {
            'suspended' => 'user_blocked',
            'active' => 'user_unblocked',
            'deleted' => 'user_deleted',
            default => 'user_status_changed'
        };
        $logStmt->execute([
            $user['user_id'],
            $action,
            $id,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);

        Response::success("User status updated to {$newStatus}");
    }

    /**
     * DELETE /api/admin/users/{id}
     */
    public function delete(array $user, int $id): void
    {
        if ($id === (int) $user['user_id']) {
            Response::error('You cannot delete your own account');
        }

        $db = Database::getConnection();

        $stmt = $db->prepare("SELECT id, role FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $targetUser = $stmt->fetch();

        if (!$targetUser) {
            Response::notFound('User');
        }

        if ($targetUser['role'] === 'admin') {
            Response::error('Cannot delete admin accounts');
        }

        // Soft delete
        $updateStmt = $db->prepare("UPDATE users SET status = 'deleted', updated_at = NOW() WHERE id = ?");
        $updateStmt->execute([$id]);

        Response::success('User deleted successfully');
    }
}
