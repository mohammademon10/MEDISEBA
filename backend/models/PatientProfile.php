<?php
/**
 * MediSeba - Patient Profile Model
 * 
 * Handles patient profile management
 */

declare(strict_types=1);

namespace MediSeba\Models;

class PatientProfile extends Model
{
    protected string $table = 'patient_profiles';
    protected string $primaryKey = 'id';
    
    protected array $fillable = [
        'user_id',
        'full_name',
        'date_of_birth',
        'gender',
        'blood_group',
        'address',
        'emergency_contact_name',
        'emergency_contact_phone',
        'medical_history_summary',
        'allergies',
        'chronic_conditions',
        'profile_photo'
    ];
    
    protected array $casts = [
        'id' => 'int',
        'user_id' => 'int',
        'date_of_birth' => 'datetime',
        'allergies' => 'array',
        'chronic_conditions' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];
    
    /**
     * Find patient by user ID
     */
    public function findByUserId(int $userId): ?array
    {
        return $this->findBy('user_id', $userId);
    }
    
    /**
     * Create or update patient profile
     */
    public function createOrUpdate(int $userId, array $data): array
    {
        $existing = $this->findByUserId($userId);
        
        if ($existing) {
            $success = $this->update($existing['id'], $data);
            return [
                'success' => $success,
                'profile_id' => $existing['id'],
                'message' => $success ? 'Profile updated successfully' : 'Failed to update profile'
            ];
        } else {
            $data['user_id'] = $userId;
            $id = $this->create($data);
            return [
                'success' => true,
                'profile_id' => $id,
                'message' => 'Profile created successfully'
            ];
        }
    }
    
    /**
     * Get patient dashboard data
     */
    public function getDashboardData(int $userId): array
    {
        $profile = $this->findByUserId($userId);
        
        if (!$profile) {
            return ['success' => false, 'message' => 'Profile not found'];
        }
        
        // Get upcoming appointments
        $appointmentModel = new Appointment();
        $upcomingAppointments = $appointmentModel->getUpcomingAppointments($profile['id'], 5);
        
        // Get recent prescriptions
        $prescriptionModel = new Prescription();
        $recentPrescriptions = $prescriptionModel->getPatientPrescriptions($profile['id'], 1, 5);
        
        // Get pending payments
        $paymentModel = new Payment();
        $pendingPayments = $paymentModel->getPendingCount($profile['id']);
        
        // Get appointment statistics for this specific patient
        $statsData = $this->db->prepare(
            "SELECT COUNT(*) as total_appointments,
             SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
             FROM appointments WHERE patient_id = ?"
        );
        $statsData->execute([$profile['id']]);
        $stats = $statsData->fetch() ?: ['total_appointments' => 0, 'completed' => 0];
        
        return [
            'success' => true,
            'profile' => $profile,
            'upcoming_appointments' => $upcomingAppointments,
            'recent_prescriptions' => $recentPrescriptions['items'] ?? [],
            'pending_payments' => $pendingPayments,
            'stats' => [
                'total_appointments' => $stats['total_appointments'] ?? 0,
                'completed_appointments' => $stats['completed'] ?? 0
            ]
        ];
    }
    
    /**
     * Search patients (for doctors)
     */
    public function search(string $query, int $limit = 20): array
    {
        $sql = "SELECT p.*, u.phone
                FROM {$this->table} p
                JOIN users u ON p.user_id = u.id
                WHERE p.full_name LIKE ? OR u.phone LIKE ?
                LIMIT ?";
        
        $searchTerm = "%{$query}%";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$searchTerm, $searchTerm, $limit]);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Get patient medical history
     */
    public function getMedicalHistory(int $patientId): array
    {
        // Get all appointments with prescriptions
        $sql = "SELECT a.*, 
                d.full_name as doctor_name, d.specialty,
                p.prescription_number, p.symptoms, p.diagnosis, p.medicine_list, 
                p.advice, p.follow_up_date, p.created_at as prescription_date
                FROM appointments a
                JOIN doctor_profiles d ON a.doctor_id = d.id
                LEFT JOIN prescriptions p ON a.id = p.appointment_id AND p.is_deleted = 0
                WHERE a.patient_id = ? AND a.status = 'completed'
                ORDER BY a.appointment_date DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$patientId]);
        
        $history = $stmt->fetchAll();
        
        foreach ($history as &$record) {
            if ($record['medicine_list']) {
                $record['medicine_list'] = json_decode($record['medicine_list'], true);
            }
        }
        
        return $history;
    }
    
    /**
     * Update allergies
     */
    public function updateAllergies(int $profileId, array $allergies): bool
    {
        return $this->update($profileId, ['allergies' => $allergies]);
    }
    
    /**
     * Update chronic conditions
     */
    public function updateChronicConditions(int $profileId, array $conditions): bool
    {
        return $this->update($profileId, ['chronic_conditions' => $conditions]);
    }
    
    /**
     * Get patient statistics (for admin)
     */
    public function getStatistics(): array
    {
        $sql = "SELECT 
            COUNT(*) as total_patients,
            SUM(CASE WHEN gender = 'male' THEN 1 ELSE 0 END) as male_patients,
            SUM(CASE WHEN gender = 'female' THEN 1 ELSE 0 END) as female_patients,
            SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as new_today
        FROM {$this->table}";
        
        $stmt = $this->db->query($sql);
        return $stmt->fetch();
    }
}
