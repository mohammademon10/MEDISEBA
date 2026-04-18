<?php
/**
 * MediSeba - Admin Dashboard Controller
 * 
 * Provides dashboard statistics and recent activity for admin panel
 */

declare(strict_types=1);

namespace MediSeba\Controllers\Admin;

use MediSeba\Utils\Response;
use MediSeba\Config\Database;

class AdminDashboardController
{
    /**
     * GET /api/admin/dashboard/stats
     */
    public function stats(array $user): void
    {
        $db = Database::getConnection();

        // User counts
        $userStats = $db->query("
            SELECT 
                COUNT(*) as total_users,
                SUM(CASE WHEN role = 'patient' THEN 1 ELSE 0 END) as total_patients,
                SUM(CASE WHEN role = 'doctor' THEN 1 ELSE 0 END) as total_doctors,
                SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as total_admins,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_users,
                SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as blocked_users,
                SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as new_today
            FROM users
            WHERE status != 'deleted'
        ")->fetch();

        // Appointment counts
        $appointmentStats = $db->query("
            SELECT 
                COUNT(*) as total_appointments,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN appointment_date = CURDATE() THEN 1 ELSE 0 END) as today_count
            FROM appointments
        ")->fetch();

        // Revenue
        $revenueStats = $db->query("
            SELECT 
                COALESCE(SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END), 0) as total_revenue,
                COALESCE(SUM(CASE WHEN status = 'success' AND DATE(paid_at) = CURDATE() THEN amount ELSE 0 END), 0) as today_revenue,
                COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) as pending_revenue,
                COUNT(CASE WHEN status = 'success' THEN 1 END) as successful_payments,
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_payments
            FROM payments
        ")->fetch();

        // Prescription counts
        $prescriptionStats = $db->query("
            SELECT 
                COUNT(*) as total_prescriptions,
                SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today_prescriptions
            FROM prescriptions
            WHERE is_deleted = 0
        ")->fetch();

        // Unverified doctors
        $unverifiedDoctors = $db->query("
            SELECT COUNT(*) as count FROM doctor_profiles WHERE is_verified = 0
        ")->fetch();

        Response::success('Dashboard stats retrieved', [
            'users' => $userStats,
            'appointments' => $appointmentStats,
            'revenue' => $revenueStats,
            'prescriptions' => $prescriptionStats,
            'unverified_doctors' => (int) $unverifiedDoctors['count']
        ]);
    }

    /**
     * GET /api/admin/dashboard/activity
     */
    public function recentActivity(array $user, array $request): void
    {
        $db = Database::getConnection();
        $limit = min(50, max(1, (int) ($request['limit'] ?? 20)));

        $stmt = $db->prepare("
            SELECT 
                al.id,
                al.action,
                al.entity_type,
                al.entity_id,
                al.ip_address,
                al.created_at,
                u.email as user_email,
                u.role as user_role
            FROM activity_logs al
            LEFT JOIN users u ON al.user_id = u.id
            ORDER BY al.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $activities = $stmt->fetchAll();

        Response::success('Recent activity retrieved', [
            'activities' => $activities
        ]);
    }
}
