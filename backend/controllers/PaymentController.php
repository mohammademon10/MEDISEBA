<?php
/**
 * MediSeba - Payment Controller
 * 
 * Handles payment processing and callbacks
 */

declare(strict_types=1);

namespace MediSeba\Controllers;

use MediSeba\Config\Environment;
use MediSeba\Utils\Response;
use MediSeba\Utils\SimplePdfDocument;
use MediSeba\Utils\Validator;
use MediSeba\Models\Payment;
use MediSeba\Models\Appointment;
use MediSeba\Models\DoctorProfile;
use MediSeba\Models\PatientProfile;

class PaymentController
{
    private Payment $paymentModel;
    private Appointment $appointmentModel;
    private DoctorProfile $doctorModel;
    private PatientProfile $patientModel;
    
    public function __construct()
    {
        $this->paymentModel = new Payment();
        $this->appointmentModel = new Appointment();
        $this->doctorModel = new DoctorProfile();
        $this->patientModel = new PatientProfile();
    }
    
    /**
     * Get payment by ID
     * GET /api/payments/{id}
     */
    public function show(int $id, array $user): void
    {
        $payment = $this->getAuthorizedPaymentOrFail($id, $user);
        Response::success('Payment retrieved', $payment);
    }
    
    /**
     * Get payment by number
     * GET /api/payments/number/{number}
     */
    public function showByNumber(string $number, array $user): void
    {
        $payment = $this->paymentModel->findByNumber($number);
        
        if (!$payment) {
            Response::notFound('Payment');
        }
        
        // Check permission
        $patient = $this->patientModel->findByUserId($user['user_id']);
        $doctor = $this->doctorModel->findByUserId($user['user_id']);
        
        $isAuthorized = 
            ($patient && $payment['patient_id'] == $patient['id']) ||
            ($doctor && $payment['doctor_id'] == $doctor['id']) ||
            $user['role'] === 'admin';
        
        if (!$isAuthorized) {
            Response::forbidden('You do not have permission to view this payment');
        }
        
        $fullDetails = $this->paymentModel->getFullDetails((int) $payment['id']) ?? $payment;

        Response::success('Payment retrieved', $fullDetails);
    }

    /**
     * Download receipt PDF
     * GET /api/payments/{id}/receipt
     */
    public function downloadReceipt(int $id, array $user): void
    {
        $payment = $this->getAuthorizedPaymentOrFail($id, $user);
        $pdf = $this->buildReceiptPdf($payment);
        $filename = 'receipt-' . ($payment['payment_number'] ?? ('payment-' . $id)) . '.pdf';

        Response::download($pdf, $filename, 'application/pdf');
    }
    
    /**
     * Get patient's payments
     * GET /api/payments/my-payments
     */
    public function myPayments(array $request, array $user): void
    {
        if ($user['role'] !== 'patient') {
            Response::forbidden('Only patients can access this endpoint');
        }
        
        $patient = $this->patientModel->findByUserId($user['user_id']);
        
        if (!$patient) {
            Response::notFound('Patient profile');
        }
        
        $page = (int) ($request['page'] ?? 1);
        $perPage = min((int) ($request['per_page'] ?? 20), 50);
        
        $result = $this->paymentModel->getPatientPayments($patient['id'], $page, $perPage);
        
        Response::paginated(
            $result['items'],
            $result['total'],
            $result['page'],
            $result['per_page']
        );
    }
    
    /**
     * Get doctor's payments/revenue
     * GET /api/payments/doctor-payments
     */
    public function doctorPayments(array $request, array $user): void
    {
        if ($user['role'] !== 'doctor') {
            Response::forbidden('Only doctors can access this endpoint');
        }
        
        $doctor = $this->doctorModel->findByUserId($user['user_id']);
        
        if (!$doctor) {
            Response::notFound('Doctor profile');
        }
        
        $startDate = $request['start_date'] ?? null;
        $endDate = $request['end_date'] ?? null;
        
        $payments = $this->paymentModel->getDoctorPayments($doctor['id'], $startDate, $endDate);
        
        Response::success('Payments retrieved', $payments);
    }
    
    /**
     * Get payment statistics
     * GET /api/payments/statistics
     */
    public function statistics(array $request, array $user): void
    {
        $doctorId = null;
        
        if ($user['role'] === 'doctor') {
            $doctor = $this->doctorModel->findByUserId($user['user_id']);
            $doctorId = $doctor['id'] ?? null;
        }
        
        $days = min((int) ($request['days'] ?? 30), 365);
        
        $stats = $this->paymentModel->getRevenueStats($doctorId, $days);
        
        Response::success('Payment statistics', $stats);
    }
    
    /**
     * Get daily revenue breakdown
     * GET /api/payments/daily-revenue
     */
    public function dailyRevenue(array $request, array $user): void
    {
        $doctorId = null;
        
        if ($user['role'] === 'doctor') {
            $doctor = $this->doctorModel->findByUserId($user['user_id']);
            $doctorId = $doctor['id'] ?? null;
        }
        
        $days = min((int) ($request['days'] ?? 30), 365);
        
        $revenue = $this->paymentModel->getDailyRevenue($doctorId, $days);
        
        Response::success('Daily revenue', $revenue);
    }
    
    /**
     * Handle payment callback from gateway
     * POST /api/payments/callback
     */
    public function callback(array $request): void
    {
        // Validate required fields
        $validator = Validator::quick($request, [
            'payment_number' => 'required',
            'status' => 'required|in:success,failed,pending',
            'transaction_id' => 'max:255'
        ]);
        
        if (!$validator['valid']) {
            Response::validationError($validator['errors']);
        }
        
        // Verify callback signature (implement based on your payment gateway)
        // This is a placeholder for signature verification
        $isValidSignature = $this->verifyCallbackSignature($request);
        
        if (!$isValidSignature) {
            Response::error('Invalid callback signature', [], Response::HTTP_FORBIDDEN);
        }
        
        $result = $this->paymentModel->handleCallback($request['payment_number'], $request);
        
        if ($result['success']) {
            Response::success('Payment processed successfully');
        } else {
            Response::error($result['message']);
        }
    }
    
    /**
     * Initiate payment (for patient)
     * POST /api/payments/initiate
     */
    public function initiate(array $request, array $user): void
    {
        if ($user['role'] !== 'patient') {
            Response::forbidden('Only patients can initiate payments');
        }

        $patient = $this->patientModel->findByUserId($user['user_id']);
        
        if (!$patient) {
            Response::notFound('Patient profile');
        }

        $paymentMethod = $request['payment_method'] ?? 'online';
        $methodValidator = Validator::quick(['payment_method' => $paymentMethod], [
            'payment_method' => 'required|in:cash,card,mobile_banking,online'
        ]);

        if (!$methodValidator['valid']) {
            Response::validationError($methodValidator['errors']);
        }

        $appointment = null;
        $existingPayment = null;

        if (!empty($request['payment_number'])) {
            $existingPayment = $this->paymentModel->findByNumber((string) $request['payment_number']);

            if (!$existingPayment) {
                Response::notFound('Payment');
            }

            if ($existingPayment['patient_id'] !== $patient['id']) {
                Response::forbidden('You can only pay for your own appointments');
            }

            $appointment = $this->appointmentModel->find((int) $existingPayment['appointment_id']);
        } else {
            $validator = Validator::quick($request, [
                'appointment_id' => 'required|integer'
            ]);

            if (!$validator['valid']) {
                Response::validationError($validator['errors']);
            }

            $appointment = $this->appointmentModel->find((int) $request['appointment_id']);

            if (!$appointment) {
                Response::notFound('Appointment');
            }

            if ($appointment['patient_id'] !== $patient['id']) {
                Response::forbidden('You can only pay for your own appointments');
            }

            $existingPayment = $this->paymentModel->getByAppointment($appointment['id']);
        }

        if (!$appointment) {
            Response::notFound('Appointment');
        }
        
        if ($existingPayment) {
            if ($existingPayment['status'] === 'success') {
                Response::success('Payment already completed', [
                    'payment_number' => $existingPayment['payment_number'],
                    'appointment_id' => $appointment['id'],
                    'amount' => $existingPayment['amount'],
                    'payment_method' => $existingPayment['payment_method'] ?? $paymentMethod,
                    'status' => $existingPayment['status'],
                    'gateway_url' => null,
                    'instructions' => 'Payment has already been completed for this appointment.'
                ]);
            }

            $this->paymentModel->update((int) $existingPayment['id'], [
                'payment_method' => $paymentMethod
            ]);

            if ($this->shouldCompleteDirectPayment($paymentMethod)) {
                $this->completeDirectPayment((int) $existingPayment['id'], (int) $appointment['id'], $paymentMethod);

                Response::success('Payment completed', [
                    'payment_number' => $existingPayment['payment_number'],
                    'appointment_id' => $appointment['id'],
                    'amount' => $existingPayment['amount'],
                    'payment_method' => $paymentMethod,
                    'status' => 'success',
                    'is_direct' => true,
                    'gateway_url' => null,
                    'instructions' => 'Payment recorded successfully in MediSeba. No external gateway is required.'
                ]);
            }

            Response::success('Payment already initiated', [
                'payment_number' => $existingPayment['payment_number'],
                'appointment_id' => $appointment['id'],
                'amount' => $existingPayment['amount'],
                'payment_method' => $paymentMethod,
                'status' => $existingPayment['status'],
                'gateway_url' => null,
                'instructions' => $this->getPaymentInstructions($paymentMethod)
            ]);
        }
        
        // Get doctor's fee
        $doctor = $this->doctorModel->find($appointment['doctor_id']);
        
        if (!$doctor) {
            Response::notFound('Doctor');
        }
        
        // Create payment record
        $result = $this->paymentModel->createPayment([
            'appointment_id' => $appointment['id'],
            'patient_id' => $patient['id'],
            'doctor_id' => $doctor['id'],
            'amount' => $doctor['consultation_fee'],
            'payment_method' => $paymentMethod
        ]);
        
        if ($result['success']) {
            // TODO: Integrate with payment gateway
            // Return payment gateway URL or instructions

            if ($this->shouldCompleteDirectPayment($paymentMethod)) {
                $this->completeDirectPayment((int) $result['payment_id'], (int) $appointment['id'], $paymentMethod);

                Response::success('Payment completed', [
                    'payment_number' => $result['payment_number'],
                    'appointment_id' => $appointment['id'],
                    'amount' => $doctor['consultation_fee'],
                    'payment_method' => $paymentMethod,
                    'status' => 'success',
                    'is_direct' => true,
                    'gateway_url' => null,
                    'instructions' => 'Payment recorded successfully in MediSeba. No external gateway is required.'
                ]);
            }
            
            Response::success('Payment initiated', [
                'payment_number' => $result['payment_number'],
                'appointment_id' => $appointment['id'],
                'amount' => $doctor['consultation_fee'],
                'payment_method' => $paymentMethod,
                'gateway_url' => null, // Set this to your payment gateway URL
                'instructions' => $this->getPaymentInstructions($paymentMethod)
            ]);
        } else {
            Response::error('Failed to initiate payment');
        }
    }
    
    /**
     * Process refund (for admin/doctor)
     * POST /api/payments/{id}/refund
     */
    public function refund(int $id, array $request, array $user): void
    {
        if (!in_array($user['role'], ['admin', 'doctor'], true)) {
            Response::forbidden('Only admins and doctors can process refunds');
        }
        
        $payment = $this->paymentModel->find($id);
        
        if (!$payment) {
            Response::notFound('Payment');
        }
        
        // If doctor, verify ownership
        if ($user['role'] === 'doctor') {
            $doctor = $this->doctorModel->findByUserId($user['user_id']);
            
            if ($payment['doctor_id'] !== $doctor['id']) {
                Response::forbidden('You can only refund your own payments');
            }
        }
        
        $validator = Validator::quick($request, [
            'amount' => 'required|numeric|min:0',
            'reason' => 'required|max:500'
        ]);
        
        if (!$validator['valid']) {
            Response::validationError($validator['errors']);
        }
        
        $result = $this->paymentModel->processRefund($id, $request['amount'], $request['reason']);
        
        if ($result['success']) {
            Response::success('Refund processed successfully');
        } else {
            Response::error($result['message']);
        }
    }
    
    /**
     * Verify callback signature using HMAC-SHA256
     * 
     * The payment gateway must send an X-Signature header containing
     * the HMAC-SHA256 hash of the raw request body, signed with the
     * shared secret (PAYMENT_GATEWAY_SECRET from .env).
     */
    private function verifyCallbackSignature(array $request): bool
    {
        // Get the signature from the request header
        $receivedSignature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
        
        if (empty($receivedSignature)) {
            error_log('Payment callback rejected: missing X-Signature header');
            return false;
        }
        
        // Get the shared secret from environment configuration
        $secret = Environment::get('PAYMENT_GATEWAY_SECRET', '');
        
        if (empty($secret)) {
            error_log('Payment callback rejected: PAYMENT_GATEWAY_SECRET is not configured');
            return false;
        }
        
        // Read the raw request body for signature computation
        // We use the raw body (not the parsed $request array) to ensure
        // the exact bytes the gateway signed are what we verify against.
        $rawBody = file_get_contents('php://input');
        
        if ($rawBody === false || $rawBody === '') {
            error_log('Payment callback rejected: empty request body');
            return false;
        }
        
        // Compute the expected HMAC-SHA256 signature
        $expectedSignature = hash_hmac('sha256', $rawBody, $secret);
        
        // Use timing-safe comparison to prevent timing attacks
        if (!hash_equals($expectedSignature, $receivedSignature)) {
            error_log('Payment callback rejected: signature mismatch');
            return false;
        }
        
        return true;
    }
    
    /**
     * Get payment instructions based on method
     */
    private function getPaymentInstructions(string $method): string
    {
        if ($this->isDemoPaymentMode()) {
            return match ($method) {
                'cash' => 'Please pay at the clinic during your visit.',
                'card' => 'Demo mode is enabled, so card payments are recorded inside MediSeba without an external gateway.',
                'mobile_banking' => 'Demo mode is enabled, so mobile banking payments are recorded inside MediSeba without an external gateway.',
                'online' => 'Demo mode is enabled, so online payments are recorded inside MediSeba without an external gateway.',
                default => 'Please follow the payment instructions.'
            };
        }

        return match ($method) {
            'cash' => 'Please pay at the clinic during your visit.',
            'card' => 'Card payments are pending until your live payment gateway confirms the transaction.',
            'mobile_banking' => 'Mobile banking payments are pending until your live payment gateway confirms the transaction.',
            'online' => 'Online payments are pending until your live payment gateway confirms the transaction.',
            default => 'Please follow the payment instructions.'
        };
    }

    private function shouldCompleteDirectPayment(string $paymentMethod): bool
    {
        if (!$this->isDemoPaymentMode()) {
            return false;
        }

        return in_array($paymentMethod, ['card', 'mobile_banking', 'online'], true);
    }

    private function isDemoPaymentMode(): bool
    {
        $gatewayMode = strtolower(trim((string) Environment::get('PAYMENT_GATEWAY', 'sandbox')));

        return in_array($gatewayMode, ['sandbox', 'demo', 'local'], true);
    }

    private function completeDirectPayment(int $paymentId, int $appointmentId, string $paymentMethod): void
    {
        $transactionId = 'MSB-' . strtoupper($paymentMethod) . '-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));

        $this->paymentModel->markAsSuccess($paymentId, $transactionId, [
            'recorded_in_app' => true,
            'payment_method' => $paymentMethod,
            'completed_at' => date('c')
        ]);

        $this->appointmentModel->update($appointmentId, ['status' => 'confirmed']);
    }

    private function getAuthorizedPaymentOrFail(int $id, array $user): array
    {
        $payment = $this->paymentModel->getFullDetails($id);

        if (!$payment) {
            Response::notFound('Payment');
        }

        $patient = $this->patientModel->findByUserId($user['user_id']);
        $doctor = $this->doctorModel->findByUserId($user['user_id']);

        $isAuthorized =
            ($patient && (int) $payment['patient_id'] === (int) $patient['id']) ||
            ($doctor && (int) $payment['doctor_id'] === (int) $doctor['id']) ||
            $user['role'] === 'admin';

        if (!$isAuthorized) {
            Response::forbidden('You do not have permission to view this payment');
        }

        return $payment;
    }

    private function buildReceiptPdf(array $payment): string
    {
        $document = new SimplePdfDocument('Receipt ' . ($payment['payment_number'] ?? ''));
        $document->addTitle('MediSeba Payment Receipt');

        $document->addMetaLine('Receipt Number', $this->stringOrFallback($payment['payment_number'] ?? null));
        $document->addMetaLine('Status', strtoupper($this->stringOrFallback($payment['status'] ?? null, 'pending')));
        $document->addMetaLine('Generated On', date('M j, Y g:i A'));
        $document->addMetaLine('Paid On', $this->formatDateTime($payment['paid_at'] ?? null));
        $document->addMetaLine('Transaction ID', $this->stringOrFallback($payment['transaction_id'] ?? null));

        $document->addSection('Patient');
        $document->addMetaLine('Name', $this->stringOrFallback($payment['patient_name'] ?? null));
        $document->addMetaLine('Email', $this->stringOrFallback($payment['patient_email'] ?? null));

        $document->addSection('Doctor');
        $document->addMetaLine('Name', $this->stringOrFallback($payment['doctor_name'] ?? null));
        $document->addMetaLine('Specialty', $this->stringOrFallback($payment['specialty'] ?? null));
        $document->addMetaLine('Clinic', $this->stringOrFallback($payment['clinic_name'] ?? null));
        $document->addMetaLine('Clinic Address', $this->stringOrFallback($payment['clinic_address'] ?? null));

        $document->addSection('Appointment');
        $document->addMetaLine('Appointment Number', $this->stringOrFallback($payment['appointment_number'] ?? null));
        $document->addMetaLine('Appointment Date', $this->formatDate($payment['appointment_date'] ?? null));
        $document->addMetaLine('Token Number', $this->stringOrFallback(isset($payment['token_number']) ? ('#' . $payment['token_number']) : null));
        $document->addMetaLine('Appointment Status', $this->stringOrFallback($payment['appointment_status'] ?? null));

        $document->addSection('Payment Summary');
        $document->addMetaLine('Amount', $this->formatAmount($payment['amount'] ?? null, $payment['currency'] ?? 'BDT'));
        $document->addMetaLine('Method', $this->stringOrFallback($payment['payment_method'] ?? null));
        $document->addMetaLine('Recorded On', $this->formatDateTime($payment['created_at'] ?? null));

        if (!empty($payment['refund_amount'])) {
            $document->addMetaLine('Refund Amount', $this->formatAmount($payment['refund_amount'], $payment['currency'] ?? 'BDT'));
            $document->addParagraph('Refund Reason: ' . $this->stringOrFallback($payment['refund_reason'] ?? null));
        }

        $document->addSpacer(8);
        $document->addParagraph('This is a system-generated payment receipt from MediSeba.', 10);

        return $document->output();
    }

    private function formatAmount(mixed $amount, string $currency = 'BDT'): string
    {
        if ($amount === null || $amount === '') {
            return 'Not provided';
        }

        return strtoupper($currency) . ' ' . number_format((float) $amount, 2, '.', '');
    }

    private function formatDate(?string $value): string
    {
        if (!$value) {
            return 'Not provided';
        }

        $timestamp = strtotime($value);

        return $timestamp ? date('M j, Y', $timestamp) : $value;
    }

    private function formatDateTime(?string $value): string
    {
        if (!$value) {
            return 'Not provided';
        }

        $timestamp = strtotime($value);

        return $timestamp ? date('M j, Y g:i A', $timestamp) : $value;
    }

    private function stringOrFallback(?string $value, string $fallback = 'Not provided'): string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : $fallback;
    }
}
