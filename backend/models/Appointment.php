<?php
/**
 * MediSeba - Appointment Model
 * 
 * Handles appointment booking with secure token generation
 */

declare(strict_types=1);

namespace MediSeba\Models;

use MediSeba\Utils\Security;
use MediSeba\Config\Database;
use PDOException;

class Appointment extends Model
{
    protected string $table = 'appointments';
    protected string $primaryKey = 'id';
    
    protected array $fillable = [
        'appointment_number',
        'patient_id',
        'doctor_id',
        'schedule_id',
        'appointment_date',
        'token_number',
        'estimated_time',
        'status',
        'cancellation_reason',
        'cancelled_by',
        'cancelled_at',
        'notes',
        'symptoms',
        'video_room_url',
        'video_call_enabled'
    ];
    
    protected array $casts = [
        'id' => 'int',
        'patient_id' => 'int',
        'doctor_id' => 'int',
        'schedule_id' => 'int',
        'token_number' => 'int',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'cancelled_at' => 'datetime'
    ];
    
    /**
     * Create new appointment with token generation
     * Utilizes Enterprise Database Stored Procedure for atomic transaction control
     */
    public function createAppointment(array $data): array
    {
        $db = Database::getConnection();
        
        try {
            // Enterprise Implementation: Executing atomic SP directly bypassing PHP memory loops
            $stmt = $db->prepare("CALL sp_book_appointment(?, ?, ?, ?, ?, @out_apt_id, @out_token)");
            $stmt->execute([
                $data['patient_id'],
                $data['doctor_id'],
                $data['schedule_id'],
                $data['appointment_date'],
                $data['symptoms'] ?? ''
            ]);
            
            $outResult = $db->query("SELECT @out_apt_id AS apt_id, @out_token AS token")->fetch();
            
            if (!$outResult || !$outResult['apt_id']) {
                throw new PDOException("Failed to generate atomic appointment");
            }

            $appointmentId = (int) $outResult['apt_id'];
            $nextToken = (int) $outResult['token'];
            
            // Retrieve generated values cleanly 
            $appointmentRow = $this->find($appointmentId);
            
            return [
                'success' => true,
                'message' => 'Appointment booked successfully',
                'appointment_id' => $appointmentId,
                'appointment_number' => $appointmentRow['appointment_number'],
                'token_number' => $nextToken,
                'estimated_time' => $appointmentRow['estimated_time']
            ];
            
        } catch (PDOException $e) {
            error_log("Appointment creation SP failed: " . $e->getMessage());
            $errorMessage = $e->getMessage();
            
            // Handle specific capacity limits thrown by SQL signal
            if (strpos($errorMessage, 'capacity limit') !== false) {
                return [
                    'success' => false,
                    'message' => 'No slots available for this date'
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Failed to book appointment. Please try again.'
            ];
        }
    }
    
    /**
     * Calculate estimated appointment time
     */
    private function calculateEstimatedTime(string $startTime, int $slotDuration, int $tokenNumber): string
    {
        $start = strtotime($startTime);
        $offsetSeconds = ($tokenNumber - 1) * $slotDuration * 60;
        $estimated = $start + $offsetSeconds;
        
        return date('H:i:s', $estimated);
    }
    
    /**
     * Find appointment by number
     */
    public function findByNumber(string $appointmentNumber): ?array
    {
        return $this->findBy('appointment_number', $appointmentNumber);
    }
    
    /**
     * Get patient appointments
     */
    public function getPatientAppointments(int $patientId, string $status = null, int $page = 1, int $perPage = 20): array
    {
        $where = ['a.patient_id = ?'];
        $params = [$patientId];
        
        if ($status) {
            $where[] = 'a.status = ?';
            $params[] = $status;
        }
        
        $whereClause = implode(' AND ', $where);
        
        // Get total count
        $countSql = "SELECT COUNT(*) FROM {$this->table} a WHERE {$whereClause}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        
        // Get paginated results with doctor info
        $offset = ($page - 1) * $perPage;
        
        $sql = "SELECT a.*, 
                d.full_name as doctor_name, d.specialty, d.clinic_name, d.clinic_address,
                d.consultation_fee,
                p.id as payment_id, p.payment_number, p.status as payment_status, p.amount as paid_amount,
                dr.id as review_id, dr.rating as review_rating, dr.review_text, dr.is_visible as review_is_visible,
                dr.created_at as review_created_at, dr.updated_at as review_updated_at,
                rx.id as prescription_id, rx.prescription_number
                FROM {$this->table} a
                JOIN doctor_profiles d ON a.doctor_id = d.id
                LEFT JOIN payments p ON a.id = p.appointment_id
                LEFT JOIN doctor_reviews dr ON a.id = dr.appointment_id
                LEFT JOIN prescriptions rx ON a.id = rx.appointment_id AND rx.is_deleted = 0
                WHERE {$whereClause}
                ORDER BY a.appointment_date DESC, a.token_number ASC
                LIMIT ? OFFSET ?";
        
        $params[] = $perPage;
        $params[] = $offset;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return [
            'items' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage)
        ];
    }
    
    /**
     * Get doctor appointments
     */
    public function getDoctorAppointments(int $doctorId, string $date = null, string $status = null): array
    {
        $where = ['a.doctor_id = ?'];
        $params = [$doctorId];
        
        if ($date) {
            $where[] = 'a.appointment_date = ?';
            $params[] = $date;
        }
        
        if ($status) {
            $where[] = 'a.status = ?';
            $params[] = $status;
        }
        
        $whereClause = implode(' AND ', $where);
        
        $sql = "SELECT a.*, 
                p.full_name as patient_name, u.email as patient_email, p.profile_photo as patient_profile_photo,
                p.date_of_birth, p.gender, p.blood_group,
                rx.id as prescription_id, rx.prescription_number
                FROM {$this->table} a
                JOIN patient_profiles p ON a.patient_id = p.id
                JOIN users u ON p.user_id = u.id
                LEFT JOIN prescriptions rx ON a.id = rx.appointment_id AND rx.is_deleted = 0
                WHERE {$whereClause}
                ORDER BY a.appointment_date DESC, a.token_number ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Get today's appointments for doctor
     */
    public function getTodayAppointments(int $doctorId): array
    {
        return $this->getDoctorAppointments($doctorId, date('Y-m-d'));
    }
    
    /**
     * Update appointment status
     */
    public function updateStatus(int $appointmentId, string $status, array $additionalData = []): bool
    {
        $data = array_merge(['status' => $status], $additionalData);
        
        if ($status === 'cancelled') {
            $data['cancelled_at'] = date('Y-m-d H:i:s');
        }
        
        return $this->update($appointmentId, $data);
    }
    
    /**
     * Cancel appointment
     */
    public function cancel(int $appointmentId, string $reason, string $cancelledBy): array
    {
        $appointment = $this->find($appointmentId);
        
        if (!$appointment) {
            return ['success' => false, 'message' => 'Appointment not found'];
        }
        
        if ($appointment['status'] === 'cancelled') {
            return ['success' => false, 'message' => 'Appointment already cancelled'];
        }
        
        if ($appointment['status'] === 'completed') {
            return ['success' => false, 'message' => 'Cannot cancel completed appointment'];
        }
        
        $success = $this->update($appointmentId, [
            'status' => 'cancelled',
            'cancellation_reason' => $reason,
            'cancelled_by' => $cancelledBy,
            'cancelled_at' => date('Y-m-d H:i:s')
        ]);
        
        return [
            'success' => $success,
            'message' => $success ? 'Appointment cancelled successfully' : 'Failed to cancel appointment'
        ];
    }
    
    /**
     * Check if patient has appointment with doctor on date
     */
    public function hasAppointment(int $patientId, int $doctorId, string $date): bool
    {
        $sql = "SELECT 1 FROM {$this->table} 
                WHERE patient_id = ? AND doctor_id = ? AND appointment_date = ? 
                AND status NOT IN ('cancelled', 'no_show')
                LIMIT 1";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$patientId, $doctorId, $date]);
        
        return $stmt->fetch() !== false;
    }
    
    /**
     * Get appointment statistics
     */
    public function getStatistics(int $doctorId = null, int $patientId = null): array
    {
        $whereClause = '';
        $params = [];
        
        if ($doctorId) {
            $whereClause = 'WHERE doctor_id = ?';
            $params[] = $doctorId;
        } elseif ($patientId) {
            $whereClause = 'WHERE patient_id = ?';
            $params[] = $patientId;
        }
        
        $sql = "SELECT 
            COUNT(*) as total_appointments,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
            SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) as no_show,
            SUM(CASE WHEN appointment_date = CURDATE() THEN 1 ELSE 0 END) as today_count
        FROM {$this->table} {$whereClause}";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetch();
    }
    
    /**
     * Get upcoming appointments for patient
     */
    public function getUpcomingAppointments(int $patientId, int $limit = 5): array
    {
        $sql = "SELECT a.*, 
                d.full_name as doctor_name, d.specialty, d.clinic_name, d.clinic_address
                FROM {$this->table} a
                JOIN doctor_profiles d ON a.doctor_id = d.id
                WHERE a.patient_id = ? 
                AND a.appointment_date >= CURDATE()
                AND a.status IN ('pending', 'confirmed')
                ORDER BY a.appointment_date ASC, a.token_number ASC
                LIMIT ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$patientId, $limit]);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Get appointment by ID with full details
     */
    public function getFullDetails(int $appointmentId): ?array
    {
        $sql = "SELECT a.*,
                d.full_name as doctor_name, d.specialty, d.qualification, 
                d.clinic_name, d.clinic_address, d.consultation_fee,
                p.full_name as patient_name, u.email as patient_email, p.profile_photo as patient_profile_photo,
                p.date_of_birth, p.gender, p.blood_group,
                pay.id as payment_id, pay.payment_number, pay.currency as payment_currency,
                pay.status as payment_status, pay.amount as paid_amount, pay.payment_method,
                pay.transaction_id, pay.paid_at, pay.created_at as payment_created_at,
                dr.id as review_id, dr.rating as review_rating, dr.review_text, dr.is_visible as review_is_visible,
                dr.created_at as review_created_at, dr.updated_at as review_updated_at,
                rx.id as prescription_id, rx.prescription_number, rx.symptoms, rx.diagnosis, rx.medicine_list, 
                rx.dosage_instructions, rx.advice, rx.follow_up_date
                FROM {$this->table} a
                JOIN doctor_profiles d ON a.doctor_id = d.id
                JOIN patient_profiles p ON a.patient_id = p.id
                JOIN users u ON p.user_id = u.id
                LEFT JOIN payments pay ON a.id = pay.appointment_id
                LEFT JOIN doctor_reviews dr ON a.id = dr.appointment_id
                LEFT JOIN prescriptions rx ON a.id = rx.appointment_id AND rx.is_deleted = 0
                WHERE a.id = ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$appointmentId]);
        
        $result = $stmt->fetch();
        
        if ($result && $result['medicine_list']) {
            $result['medicine_list'] = json_decode($result['medicine_list'], true);
        }
        
        return $result ?: null;
    }
}
