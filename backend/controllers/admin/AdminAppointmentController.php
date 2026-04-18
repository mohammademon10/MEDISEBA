<?php
/**
 * MediSeba - Admin Appointment Controller
 * 
 * Appointment management from admin panel
 */

declare(strict_types=1);

namespace MediSeba\Controllers\Admin;

use MediSeba\Utils\Response;
use MediSeba\Config\Database;

class AdminAppointmentController
{
    /**
     * GET /api/admin/appointments
     */
    public function index(array $user, array $request): void
    {
        $db = Database::getConnection();
        $page = max(1, (int) ($request['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($request['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;
        $status = $request['status'] ?? '';
        $search = trim($request['search'] ?? '');
        $dateFrom = $request['date_from'] ?? '';
        $dateTo = $request['date_to'] ?? '';

        $where = ['1=1'];
        $params = [];

        if (in_array($status, ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show'], true)) {
            $where[] = 'a.status = ?';
            $params[] = $status;
        }

        if ($search !== '') {
            $where[] = '(a.appointment_number LIKE ? OR pp.full_name LIKE ? OR dp.full_name LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        if ($dateFrom !== '') {
            $where[] = 'a.appointment_date >= ?';
            $params[] = $dateFrom;
        }

        if ($dateTo !== '') {
            $where[] = 'a.appointment_date <= ?';
            $params[] = $dateTo;
        }

        $whereClause = implode(' AND ', $where);

        $countStmt = $db->prepare("
            SELECT COUNT(*) FROM appointments a
            JOIN patient_profiles pp ON a.patient_id = pp.id
            JOIN doctor_profiles dp ON a.doctor_id = dp.id
            WHERE {$whereClause}
        ");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $params[] = $perPage;
        $params[] = $offset;
        $stmt = $db->prepare("
            SELECT 
                a.id, a.appointment_number, a.appointment_date, a.token_number,
                a.estimated_time, a.status, a.symptoms, a.notes,
                a.cancellation_reason, a.cancelled_by, a.created_at,
                pp.full_name as patient_name, pp.profile_photo as patient_photo,
                dp.full_name as doctor_name, dp.specialty, dp.profile_photo as doctor_photo,
                pu.email as patient_email, du.email as doctor_email
            FROM appointments a
            JOIN patient_profiles pp ON a.patient_id = pp.id
            JOIN doctor_profiles dp ON a.doctor_id = dp.id
            JOIN users pu ON pp.user_id = pu.id
            JOIN users du ON dp.user_id = du.id
            WHERE {$whereClause}
            ORDER BY a.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        $appointments = $stmt->fetchAll();

        Response::success('Appointments retrieved', [
            'items' => $appointments,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage)
        ]);
    }

    /**
     * POST /api/admin/appointments/{id}/cancel
     */
    public function cancel(array $user, int $id, array $request): void
    {
        $reason = trim($request['reason'] ?? 'Cancelled by admin');

        $db = Database::getConnection();

        $stmt = $db->prepare("SELECT id, status FROM appointments WHERE id = ?");
        $stmt->execute([$id]);
        $appointment = $stmt->fetch();

        if (!$appointment) {
            Response::notFound('Appointment');
        }

        if (in_array($appointment['status'], ['completed', 'cancelled'], true)) {
            Response::error("Cannot cancel an appointment that is already {$appointment['status']}");
        }

        $db->beginTransaction();
        try {
            $updateStmt = $db->prepare("
                UPDATE appointments 
                SET status = 'cancelled', cancellation_reason = ?, cancelled_by = 'system', cancelled_at = NOW(), updated_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([$reason, $id]);

            // Database optimization: `appointment_status_history` is autonomously updated via MySQL Trigger `trg_appointment_status_audit`

            // Log activity
            $logStmt = $db->prepare("
                INSERT INTO activity_logs (user_id, action, entity_type, entity_id, ip_address, user_agent)
                VALUES (?, 'appointment_cancelled_by_admin', 'appointment', ?, ?, ?)
            ");
            $logStmt->execute([
                $user['user_id'],
                $id,
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);

            $db->commit();
            Response::success('Appointment cancelled successfully');
        } catch (\Exception $e) {
            $db->rollBack();
            Response::serverError('Failed to cancel appointment');
        }
    }
}
