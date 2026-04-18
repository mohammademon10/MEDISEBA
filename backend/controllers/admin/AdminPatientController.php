<?php
/**
 * MediSeba - Admin Patient Controller
 * 
 * Patient management from admin panel
 */

declare(strict_types=1);

namespace MediSeba\Controllers\Admin;

use MediSeba\Utils\Response;
use MediSeba\Config\Database;

class AdminPatientController
{
    /**
     * GET /api/admin/patients
     */
    public function index(array $user, array $request): void
    {
        $db = Database::getConnection();
        $page = max(1, (int) ($request['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($request['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;
        $search = trim($request['search'] ?? '');

        $where = ["u.role = 'patient'", "u.status != 'deleted'"];
        $params = [];

        if ($search !== '') {
            $where[] = '(pp.full_name LIKE ? OR u.email LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $whereClause = implode(' AND ', $where);

        $countStmt = $db->prepare("
            SELECT COUNT(*) FROM users u
            LEFT JOIN patient_profiles pp ON u.id = pp.user_id
            WHERE {$whereClause}
        ");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $params[] = $perPage;
        $params[] = $offset;
        $stmt = $db->prepare("
            SELECT 
                u.id as user_id, u.email, u.phone, u.status, u.last_login_at, u.created_at,
                pp.id as profile_id, pp.full_name, pp.date_of_birth, pp.gender,
                pp.blood_group, pp.address, pp.profile_photo
            FROM users u
            LEFT JOIN patient_profiles pp ON u.id = pp.user_id
            WHERE {$whereClause}
            ORDER BY u.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        $patients = $stmt->fetchAll();

        Response::success('Patients retrieved', [
            'items' => $patients,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage)
        ]);
    }

    /**
     * PATCH /api/admin/patients/{id}/status
     */
    public function updateStatus(array $user, int $id, array $request): void
    {
        $newStatus = $request['status'] ?? '';

        if (!in_array($newStatus, ['active', 'suspended', 'deleted'], true)) {
            Response::error('Invalid status. Use: active, suspended, or deleted');
        }

        $db = Database::getConnection();

        $stmt = $db->prepare("SELECT id, role FROM users WHERE id = ? AND role = 'patient'");
        $stmt->execute([$id]);
        $patient = $stmt->fetch();

        if (!$patient) {
            Response::notFound('Patient');
        }

        $updateStmt = $db->prepare("UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?");
        $updateStmt->execute([$newStatus, $id]);

        // Log activity
        $logStmt = $db->prepare("
            INSERT INTO activity_logs (user_id, action, entity_type, entity_id, ip_address, user_agent)
            VALUES (?, ?, 'patient', ?, ?, ?)
        ");
        $action = match ($newStatus) {
            'suspended' => 'patient_blocked',
            'active' => 'patient_unblocked',
            'deleted' => 'patient_deleted',
            default => 'patient_status_changed'
        };
        $logStmt->execute([
            $user['user_id'],
            $action,
            $id,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);

        Response::success("Patient status updated to {$newStatus}");
    }
}
