<?php
/**
 * MediSeba - Payment Model
 * 
 * Handles payment processing and management
 */

declare(strict_types=1);

namespace MediSeba\Models;

use MediSeba\Utils\Security;

class Payment extends Model
{
    protected string $table = 'payments';
    protected string $primaryKey = 'id';
    
    protected array $fillable = [
        'payment_number',
        'appointment_id',
        'patient_id',
        'doctor_id',
        'amount',
        'currency',
        'status',
        'payment_method',
        'transaction_id',
        'gateway_response',
        'paid_at',
        'refunded_at',
        'refund_amount',
        'refund_reason'
    ];
    
    protected array $hidden = ['gateway_response'];
    
    protected array $casts = [
        'id' => 'int',
        'appointment_id' => 'int',
        'patient_id' => 'int',
        'doctor_id' => 'int',
        'amount' => 'float',
        'refund_amount' => 'float',
        'paid_at' => 'datetime',
        'refunded_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];
    
    /**
     * Create payment record
     */
    public function createPayment(array $data): array
    {
        // Generate payment number
        $data['payment_number'] = Security::generatePaymentNumber();
        $data['currency'] = $data['currency'] ?? 'BDT';
        $data['status'] = 'pending';
        
        $id = $this->create($data);
        
        return [
            'success' => true,
            'payment_id' => $id,
            'payment_number' => $data['payment_number'],
            'status' => 'pending'
        ];
    }
    
    /**
     * Find payment by number
     */
    public function findByNumber(string $paymentNumber): ?array
    {
        return $this->findBy('payment_number', $paymentNumber);
    }
    
    /**
     * Process payment success
     */
    public function markAsSuccess(int $paymentId, string $transactionId, array $gatewayResponse = []): bool
    {
        return $this->update($paymentId, [
            'status' => 'success',
            'transaction_id' => $transactionId,
            'gateway_response' => json_encode($gatewayResponse),
            'paid_at' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Process payment failure
     */
    public function markAsFailed(int $paymentId, array $gatewayResponse = []): bool
    {
        return $this->update($paymentId, [
            'status' => 'failed',
            'gateway_response' => json_encode($gatewayResponse)
        ]);
    }
    
    /**
     * Process refund
     */
    public function processRefund(int $paymentId, float $amount, string $reason): array
    {
        $payment = $this->find($paymentId);
        
        if (!$payment) {
            return ['success' => false, 'message' => 'Payment not found'];
        }
        
        if ($payment['status'] !== 'success') {
            return ['success' => false, 'message' => 'Payment is not in success status'];
        }
        
        // Check if full or partial refund
        $newStatus = ($amount >= $payment['amount']) ? 'refunded' : 'partially_refunded';
        
        $success = $this->update($paymentId, [
            'status' => $newStatus,
            'refund_amount' => $amount,
            'refund_reason' => $reason,
            'refunded_at' => date('Y-m-d H:i:s')
        ]);
        
        return [
            'success' => $success,
            'message' => $success ? 'Refund processed successfully' : 'Failed to process refund'
        ];
    }
    
    /**
     * Get patient payments
     */
    public function getPatientPayments(int $patientId, int $page = 1, int $perPage = 20): array
    {
        $offset = ($page - 1) * $perPage;
        
        $sql = "SELECT p.*, 
                a.appointment_date, a.appointment_number, a.token_number,
                d.full_name as doctor_name, d.specialty
                FROM {$this->table} p
                JOIN appointments a ON p.appointment_id = a.id
                JOIN doctor_profiles d ON p.doctor_id = d.id
                WHERE p.patient_id = ?
                ORDER BY p.created_at DESC
                LIMIT ? OFFSET ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$patientId, $perPage, $offset]);
        
        $results = $stmt->fetchAll();
        
        $total = $this->count('patient_id = ?', [$patientId]);
        
        return [
            'items' => $results,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage)
        ];
    }
    
    /**
     * Get doctor payments/revenue
     */
    public function getDoctorPayments(int $doctorId, string $startDate = null, string $endDate = null): array
    {
        $where = 'p.doctor_id = ? AND p.status = ?';
        $params = [$doctorId, 'success'];
        
        if ($startDate) {
            $where .= ' AND p.paid_at >= ?';
            $params[] = $startDate . ' 00:00:00';
        }
        
        if ($endDate) {
            $where .= ' AND p.paid_at <= ?';
            $params[] = $endDate . ' 23:59:59';
        }
        
        $sql = "SELECT p.*, 
                a.appointment_date, a.appointment_number,
                pt.full_name as patient_name
                FROM {$this->table} p
                JOIN appointments a ON p.appointment_id = a.id
                JOIN patient_profiles pt ON p.patient_id = pt.id
                WHERE {$where}
                ORDER BY p.paid_at DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Get payment by appointment
     */
    public function getByAppointment(int $appointmentId): ?array
    {
        return $this->findBy('appointment_id', $appointmentId);
    }

    /**
     * Get payment with appointment, doctor, and patient details
     */
    public function getFullDetails(int $paymentId): ?array
    {
        $sql = "SELECT
                p.id,
                p.payment_number,
                p.appointment_id,
                p.patient_id,
                p.doctor_id,
                p.amount,
                p.currency,
                p.status,
                p.payment_method,
                p.transaction_id,
                p.paid_at,
                p.refunded_at,
                p.refund_amount,
                p.refund_reason,
                p.created_at,
                p.updated_at,
                a.appointment_number,
                a.appointment_date,
                a.token_number,
                a.estimated_time,
                a.status as appointment_status,
                d.full_name as doctor_name,
                d.specialty,
                d.qualification,
                d.clinic_name,
                d.clinic_address,
                pt.full_name as patient_name,
                pt.date_of_birth,
                pt.gender,
                pt.blood_group,
                u.email as patient_email
                FROM {$this->table} p
                JOIN appointments a ON p.appointment_id = a.id
                JOIN doctor_profiles d ON p.doctor_id = d.id
                JOIN patient_profiles pt ON p.patient_id = pt.id
                JOIN users u ON pt.user_id = u.id
                WHERE p.id = ?
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$paymentId]);

        $result = $stmt->fetch();

        return $result ?: null;
    }
    
    /**
     * Get revenue statistics
     */
    public function getRevenueStats(int $doctorId = null, int $days = 30): array
    {
        $where = 'status = ? AND paid_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)';
        $params = ['success', $days];
        
        if ($doctorId) {
            $where .= ' AND doctor_id = ?';
            $params[] = $doctorId;
        }
        
        $sql = "SELECT 
            COALESCE(SUM(amount), 0) as total_revenue,
            COUNT(*) as total_transactions,
            COALESCE(AVG(amount), 0) as average_amount,
            SUM(CASE WHEN DATE(paid_at) = CURDATE() THEN amount ELSE 0 END) as today_revenue,
            SUM(CASE WHEN paid_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN amount ELSE 0 END) as week_revenue
        FROM {$this->table}
        WHERE {$where}";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetch();
    }
    
    /**
     * Get daily revenue breakdown
     */
    public function getDailyRevenue(int $doctorId = null, int $days = 30): array
    {
        $where = 'status = ?';
        $params = ['success'];
        
        if ($doctorId) {
            $where .= ' AND doctor_id = ?';
            $params[] = $doctorId;
        }
        
        $sql = "SELECT 
            DATE(paid_at) as date,
            COALESCE(SUM(amount), 0) as revenue,
            COUNT(*) as transactions
        FROM {$this->table}
        WHERE {$where} AND paid_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
        GROUP BY DATE(paid_at)
        ORDER BY date DESC";
        
        $params[] = $days;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Get pending payments count
     */
    public function getPendingCount(int $patientId = null): int
    {
        $where = 'status = ?';
        $params = ['pending'];
        
        if ($patientId) {
            $where .= ' AND patient_id = ?';
            $params[] = $patientId;
        }
        
        return $this->count($where, $params);
    }
    
    /**
     * Handle payment callback from gateway
     */
    public function handleCallback(string $paymentNumber, array $callbackData): array
    {
        $payment = $this->findByNumber($paymentNumber);
        
        if (!$payment) {
            return ['success' => false, 'message' => 'Payment not found'];
        }
        
        $status = $callbackData['status'] ?? 'failed';
        $transactionId = $callbackData['transaction_id'] ?? null;
        
        if ($status === 'success' && $transactionId) {
            $this->markAsSuccess($payment['id'], $transactionId, $callbackData);
            
            // Update appointment status to confirmed
            $appointmentModel = new Appointment();
            $appointmentModel->update($payment['appointment_id'], ['status' => 'confirmed']);
            
            return ['success' => true, 'message' => 'Payment successful'];
        } else {
            $this->markAsFailed($payment['id'], $callbackData);
            return ['success' => false, 'message' => 'Payment failed'];
        }
    }
}
