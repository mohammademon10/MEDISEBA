<?php
/**
 * MediSeba - Prescription Model
 * 
 * Handles prescription creation and management
 */

declare(strict_types=1);

namespace MediSeba\Models;

use MediSeba\Utils\Security;

class Prescription extends Model
{
    protected string $table = 'prescriptions';
    protected string $primaryKey = 'id';
    
    protected array $fillable = [
        'prescription_number',
        'appointment_id',
        'patient_id',
        'doctor_id',
        'symptoms',
        'diagnosis',
        'diagnosis_codes',
        'medicine_list',
        'dosage_instructions',
        'advice',
        'follow_up_date',
        'follow_up_notes',
        'is_deleted',
        'deleted_at',
        'deleted_by'
    ];
    
    protected array $casts = [
        'id' => 'int',
        'appointment_id' => 'int',
        'patient_id' => 'int',
        'doctor_id' => 'int',
        'diagnosis_codes' => 'array',
        'medicine_list' => 'array',
        'is_deleted' => 'bool',
        'follow_up_date' => 'datetime',
        'deleted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];
    
    /**
     * Create prescription for appointment
     */
    public function createPrescription(array $data): array
    {
        // Check if prescription already exists for this appointment
        $existing = $this->findBy('appointment_id', $data['appointment_id']);
        if ($existing) {
            return [
                'success' => false,
                'message' => 'Prescription already exists for this appointment'
            ];
        }
        
        // Generate prescription number
        $data['prescription_number'] = Security::generatePrescriptionNumber();
        
        // Set default values
        $data['is_deleted'] = false;
        
        $id = $this->create($data);
        
        return [
            'success' => true,
            'message' => 'Prescription created successfully',
            'prescription_id' => $id,
            'prescription_number' => $data['prescription_number']
        ];
    }
    
    /**
     * Find prescription by number
     */
    public function findByNumber(string $prescriptionNumber): ?array
    {
        return $this->findBy('prescription_number', $prescriptionNumber);
    }
    
    /**
     * Get patient prescriptions
     */
    public function getPatientPrescriptions(int $patientId, int $page = 1, int $perPage = 20): array
    {
        $offset = ($page - 1) * $perPage;
        
        // Get total count
        $countSql = "SELECT COUNT(*) FROM {$this->table} WHERE patient_id = ? AND is_deleted = 0";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute([$patientId]);
        $total = (int) $countStmt->fetchColumn();
        
        // Get prescriptions with doctor info
        $sql = "SELECT p.*, 
                d.full_name as doctor_name, d.specialty,
                a.appointment_date, a.appointment_number
                FROM {$this->table} p
                JOIN doctor_profiles d ON p.doctor_id = d.id
                JOIN appointments a ON p.appointment_id = a.id
                WHERE p.patient_id = ? AND p.is_deleted = 0
                ORDER BY p.created_at DESC
                LIMIT ? OFFSET ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$patientId, $perPage, $offset]);
        $results = $stmt->fetchAll();
        
        // Decode JSON fields
        foreach ($results as &$result) {
            if ($result['medicine_list']) {
                $result['medicine_list'] = json_decode($result['medicine_list'], true);
            }
            if ($result['diagnosis_codes']) {
                $result['diagnosis_codes'] = json_decode($result['diagnosis_codes'], true);
            }
        }
        
        return [
            'items' => $results,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage)
        ];
    }
    
    /**
     * Get doctor prescriptions
     */
    public function getDoctorPrescriptions(int $doctorId, int $page = 1, int $perPage = 20): array
    {
        $offset = ($page - 1) * $perPage;
        
        $sql = "SELECT p.*, 
                pt.full_name as patient_name, u.email as patient_email, pt.profile_photo as patient_profile_photo,
                a.appointment_date, a.appointment_number
                FROM {$this->table} p
                JOIN patient_profiles pt ON p.patient_id = pt.id
                JOIN users u ON pt.user_id = u.id
                JOIN appointments a ON p.appointment_id = a.id
                WHERE p.doctor_id = ? AND p.is_deleted = 0
                ORDER BY p.created_at DESC
                LIMIT ? OFFSET ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$doctorId, $perPage, $offset]);
        
        $results = $stmt->fetchAll();
        
        foreach ($results as &$result) {
            if ($result['medicine_list']) {
                $result['medicine_list'] = json_decode($result['medicine_list'], true);
            }
        }
        
        $total = $this->count('doctor_id = ? AND is_deleted = 0', [$doctorId]);
        
        return [
            'items' => $results,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage)
        ];
    }
    
    /**
     * Get prescription with full details
     */
    public function getFullDetails(int $prescriptionId): ?array
    {
        $sql = "SELECT p.*,
                d.full_name as doctor_name, d.specialty, d.qualification, 
                d.registration_number, d.clinic_name, d.clinic_address,
                pt.full_name as patient_name, u.email as patient_email, pt.profile_photo as patient_profile_photo,
                pt.date_of_birth, pt.gender, pt.blood_group,
                a.appointment_date, a.appointment_number, a.symptoms as appointment_symptoms
                FROM {$this->table} p
                JOIN doctor_profiles d ON p.doctor_id = d.id
                JOIN patient_profiles pt ON p.patient_id = pt.id
                JOIN users u ON pt.user_id = u.id
                JOIN appointments a ON p.appointment_id = a.id
                WHERE p.id = ? AND p.is_deleted = 0";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$prescriptionId]);
        $result = $stmt->fetch();
        
        if (!$result) {
            return null;
        }
        
        // Decode JSON fields
        if ($result['medicine_list']) {
            $result['medicine_list'] = json_decode($result['medicine_list'], true);
        }
        if ($result['diagnosis_codes']) {
            $result['diagnosis_codes'] = json_decode($result['diagnosis_codes'], true);
        }
        
        return $result;
    }
    
    /**
     * Soft delete prescription
     */
    public function softDelete(int $prescriptionId, ?int $deletedBy = null): bool
    {
        $data = [
            'is_deleted' => true,
            'deleted_at' => date('Y-m-d H:i:s'),
        ];

        if ($deletedBy !== null) {
            $data['deleted_by'] = $deletedBy;
        }

        return $this->update($prescriptionId, $data);
    }
    
    /**
     * Update prescription
     */
    public function updatePrescription(int $prescriptionId, array $data): bool
    {
        // Don't allow changing these fields
        unset($data['prescription_number'], $data['appointment_id'], $data['patient_id'], $data['doctor_id']);
        
        return $this->update($prescriptionId, $data);
    }
    
    /**
     * Get prescriptions with upcoming follow-up
     */
    public function getUpcomingFollowUps(int $doctorId = null, int $days = 7): array
    {
        $where = 'follow_up_date IS NOT NULL AND follow_up_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY) AND follow_up_date >= CURDATE() AND is_deleted = 0';
        $params = [$days];
        
        if ($doctorId) {
            $where .= ' AND doctor_id = ?';
            $params[] = $doctorId;
        }
        
        $sql = "SELECT p.*, 
                pt.full_name as patient_name, u.email as patient_email, pt.profile_photo as patient_profile_photo
                FROM {$this->table} p
                JOIN patient_profiles pt ON p.patient_id = pt.id
                JOIN users u ON pt.user_id = u.id
                WHERE {$where}
                ORDER BY p.follow_up_date ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Search prescriptions
     */
    public function search(int $patientId, string $query): array
    {
        $sql = "SELECT p.*, d.full_name as doctor_name, d.specialty
                FROM {$this->table} p
                JOIN doctor_profiles d ON p.doctor_id = d.id
                WHERE p.patient_id = ? AND p.is_deleted = 0
                AND (p.symptoms LIKE ? OR p.diagnosis LIKE ? OR p.prescription_number LIKE ?)
                ORDER BY p.created_at DESC
                LIMIT 20";
        
        $searchTerm = "%{$query}%";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$patientId, $searchTerm, $searchTerm, $searchTerm]);
        
        $results = $stmt->fetchAll();
        
        foreach ($results as &$result) {
            if ($result['medicine_list']) {
                $result['medicine_list'] = json_decode($result['medicine_list'], true);
            }
        }
        
        return $results;
    }
}
