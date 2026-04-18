<?php
/**
 * MediSeba - Admin Doctor Controller
 * 
 * Doctor management from admin panel
 */

declare(strict_types=1);

namespace MediSeba\Controllers\Admin;

use MediSeba\Utils\Response;
use MediSeba\Config\Database;

class AdminDoctorController
{
    /**
     * GET /api/admin/doctors
     */
    public function index(array $user, array $request): void
    {
        $db = Database::getConnection();
        $page = max(1, (int) ($request['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($request['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;
        $verified = $request['verified'] ?? '';
        $search = trim($request['search'] ?? '');

        $where = ["u.status != 'deleted'"];
        $params = [];

        if ($verified === '1' || $verified === '0') {
            $where[] = 'dp.is_verified = ?';
            $params[] = (int) $verified;
        }

        if ($search !== '') {
            $where[] = '(dp.full_name LIKE ? OR dp.specialty LIKE ? OR u.email LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $whereClause = implode(' AND ', $where);

        $countStmt = $db->prepare("
            SELECT COUNT(*) FROM doctor_profiles dp
            JOIN users u ON dp.user_id = u.id
            WHERE {$whereClause}
        ");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $params[] = $perPage;
        $params[] = $offset;
        $stmt = $db->prepare("
            SELECT 
                dp.*,
                u.email, u.status as user_status, u.last_login_at, u.created_at as user_created_at
            FROM doctor_profiles dp
            JOIN users u ON dp.user_id = u.id
            WHERE {$whereClause}
            ORDER BY dp.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        $doctors = $stmt->fetchAll();

        Response::success('Doctors retrieved', [
            'items' => $doctors,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage)
        ]);
    }

    /**
     * GET /api/admin/doctors/{id}
     */
    public function show(array $user, int $id): void
    {
        $db = Database::getConnection();

        $stmt = $db->prepare("
            SELECT 
                dp.*,
                u.email, u.status as user_status, u.last_login_at, u.created_at as user_created_at
            FROM doctor_profiles dp
            JOIN users u ON dp.user_id = u.id
            WHERE dp.id = ?
        ");
        $stmt->execute([$id]);
        $doctor = $stmt->fetch();

        if (!$doctor) {
            Response::notFound('Doctor');
        }

        Response::success('Doctor retrieved', ['doctor' => $doctor]);
    }

    /**
     * PATCH /api/admin/doctors/{id}/verify
     */
    public function verify(array $user, int $id, array $request): void
    {
        $action = $request['action'] ?? '';

        if (!in_array($action, ['approve', 'reject'], true)) {
            Response::error('Invalid action. Use: approve or reject');
        }

        $db = Database::getConnection();

        $stmt = $db->prepare("SELECT id, user_id, full_name FROM doctor_profiles WHERE id = ?");
        $stmt->execute([$id]);
        $doctor = $stmt->fetch();

        if (!$doctor) {
            Response::notFound('Doctor');
        }

        $isVerified = $action === 'approve' ? 1 : 0;

        $updateStmt = $db->prepare("UPDATE doctor_profiles SET is_verified = ?, updated_at = NOW() WHERE id = ?");
        $updateStmt->execute([$isVerified, $id]);

        // Log activity
        $logStmt = $db->prepare("
            INSERT INTO activity_logs (user_id, action, entity_type, entity_id, ip_address, user_agent)
            VALUES (?, ?, 'doctor', ?, ?, ?)
        ");
        $logStmt->execute([
            $user['user_id'],
            $action === 'approve' ? 'doctor_approved' : 'doctor_rejected',
            $id,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);

        $statusLabel = $action === 'approve' ? 'approved' : 'rejected';
        Response::success("Doctor {$statusLabel} successfully");
    }

    /**
     * PUT /api/admin/doctors/{id}
     */
    public function update(array $user, int $id, array $request): void
    {
        $db = Database::getConnection();

        $stmt = $db->prepare("SELECT id FROM doctor_profiles WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            Response::notFound('Doctor');
        }

        $allowedFields = [
            'full_name', 'specialty', 'qualification', 'experience_years',
            'consultation_fee', 'clinic_name', 'clinic_address', 'bio',
            'languages', 'registration_number', 'is_verified', 'is_featured'
        ];

        $updates = [];
        $params = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $request)) {
                $value = $request[$field];
                if ($field === 'languages' && is_array($value)) {
                    $value = json_encode($value);
                }
                $updates[] = "{$field} = ?";
                $params[] = $value;
            }
        }

        if (empty($updates)) {
            Response::error('No valid fields to update');
        }

        $updates[] = "updated_at = NOW()";
        $params[] = $id;

        $sql = "UPDATE doctor_profiles SET " . implode(', ', $updates) . " WHERE id = ?";
        $updateStmt = $db->prepare($sql);
        $updateStmt->execute($params);

        Response::success('Doctor profile updated successfully');
    }
}
