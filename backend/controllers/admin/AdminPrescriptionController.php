<?php
/**
 * MediSeba - Admin Prescription Controller
 * 
 * Prescription monitoring from admin panel
 */

declare(strict_types=1);

namespace MediSeba\Controllers\Admin;

use MediSeba\Utils\Response;
use MediSeba\Config\Database;

class AdminPrescriptionController
{
    /**
     * GET /api/admin/prescriptions
     */
    public function index(array $user, array $request): void
    {
        $db = Database::getConnection();
        $page = max(1, (int) ($request['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($request['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;
        $search = trim($request['search'] ?? '');

        $where = ['rx.is_deleted = 0'];
        $params = [];

        if ($search !== '') {
            $where[] = '(rx.prescription_number LIKE ? OR rx.diagnosis LIKE ? OR pp.full_name LIKE ? OR dp.full_name LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $whereClause = implode(' AND ', $where);

        $countStmt = $db->prepare("
            SELECT COUNT(*) FROM prescriptions rx
            JOIN patient_profiles pp ON rx.patient_id = pp.id
            JOIN doctor_profiles dp ON rx.doctor_id = dp.id
            WHERE {$whereClause}
        ");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $params[] = $perPage;
        $params[] = $offset;
        $stmt = $db->prepare("
            SELECT 
                rx.id, rx.prescription_number, rx.symptoms, rx.diagnosis,
                rx.medicine_list, rx.dosage_instructions, rx.advice,
                rx.follow_up_date, rx.created_at,
                a.appointment_number, a.appointment_date,
                pp.full_name as patient_name,
                dp.full_name as doctor_name, dp.specialty
            FROM prescriptions rx
            JOIN appointments a ON rx.appointment_id = a.id
            JOIN patient_profiles pp ON rx.patient_id = pp.id
            JOIN doctor_profiles dp ON rx.doctor_id = dp.id
            WHERE {$whereClause}
            ORDER BY rx.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        $prescriptions = $stmt->fetchAll();

        Response::success('Prescriptions retrieved', [
            'items' => $prescriptions,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage)
        ]);
    }

    /**
     * GET /api/admin/prescriptions/{id}
     */
    public function show(array $user, int $id): void
    {
        $db = Database::getConnection();

        $stmt = $db->prepare("
            SELECT 
                rx.*,
                a.appointment_number, a.appointment_date,
                pp.full_name as patient_name, pp.date_of_birth, pp.gender, pp.blood_group,
                dp.full_name as doctor_name, dp.specialty, dp.qualification, dp.registration_number
            FROM prescriptions rx
            JOIN appointments a ON rx.appointment_id = a.id
            JOIN patient_profiles pp ON rx.patient_id = pp.id
            JOIN doctor_profiles dp ON rx.doctor_id = dp.id
            WHERE rx.id = ? AND rx.is_deleted = 0
        ");
        $stmt->execute([$id]);
        $prescription = $stmt->fetch();

        if (!$prescription) {
            Response::notFound('Prescription');
        }

        Response::success('Prescription retrieved', ['prescription' => $prescription]);
    }
}
