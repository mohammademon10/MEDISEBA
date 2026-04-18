<?php
/**
 * MediSeba - Appointment Controller
 * 
 * Handles appointment booking and management
 */

declare(strict_types=1);

namespace MediSeba\Controllers;

use MediSeba\Utils\Response;
use MediSeba\Utils\Validator;
use MediSeba\Models\Appointment;
use MediSeba\Models\DoctorProfile;
use MediSeba\Models\DoctorReview;
use MediSeba\Models\PatientProfile;
use MediSeba\Models\Payment;

class AppointmentController
{
    private Appointment $appointmentModel;
    private DoctorProfile $doctorModel;
    private DoctorReview $reviewModel;
    private PatientProfile $patientModel;
    
    public function __construct()
    {
        $this->appointmentModel = new Appointment();
        $this->doctorModel = new DoctorProfile();
        $this->reviewModel = new DoctorReview();
        $this->patientModel = new PatientProfile();
    }
    
    /**
     * Create new appointment
     * POST /api/appointments
     */
    public function store(array $request, array $user): void
    {
        // Verify user is a patient
        if ($user['role'] !== 'patient') {
            Response::forbidden('Only patients can book appointments');
        }
        
        // Validate input
        $validator = Validator::quick($request, [
            'doctor_id' => 'required|integer',
            'schedule_id' => 'required|integer',
            'appointment_date' => 'required|date|future',
            'notes' => 'max:500',
            'symptoms' => 'max:1000'
        ]);
        
        if (!$validator['valid']) {
            Response::validationError($validator['errors']);
        }
        
        // Get patient profile
        $patient = $this->patientModel->findByUserId($user['user_id']);
        
        if (!$patient) {
            Response::error('Patient profile not found. Please complete your profile first.');
        }
        
        // Verify doctor exists and is verified
        $doctorId = (int) $request['doctor_id'];
        $doctor = $this->doctorModel->find($doctorId);
        
        if (!$doctor || !$doctor['is_verified']) {
            Response::notFound('Doctor');
        }

        $linkedDoctorProfile = $this->doctorModel->findByUserId($user['user_id']);
        $isSelfBookingAttempt =
            (int) ($doctor['user_id'] ?? 0) === (int) $user['user_id'] ||
            ($linkedDoctorProfile && (int) $linkedDoctorProfile['id'] === $doctorId);

        if ($isSelfBookingAttempt) {
            Response::forbidden('You cannot book an appointment with your own doctor profile.');
        }
        
        // Check if patient already has appointment with this doctor on this date
        $hasAppointment = $this->appointmentModel->hasAppointment(
            $patient['id'],
            $doctorId,
            $request['appointment_date']
        );
        
        if ($hasAppointment) {
            Response::conflict('You already have an appointment with this doctor on this date');
        }
        
        // Create appointment with token generation
        $result = $this->appointmentModel->createAppointment([
            'patient_id' => $patient['id'],
            'doctor_id' => $doctorId,
            'schedule_id' => (int) $request['schedule_id'],
            'appointment_date' => $request['appointment_date'],
            'notes' => $request['notes'] ?? null,
            'symptoms' => $request['symptoms'] ?? null
        ]);
        
        if (!$result['success']) {
            Response::error($result['message']);
        }
        
        // Create pending payment
        $paymentModel = new Payment();
        $paymentResult = $paymentModel->createPayment([
            'appointment_id' => $result['appointment_id'],
            'patient_id' => $patient['id'],
            'doctor_id' => $doctorId,
            'amount' => $doctor['consultation_fee']
        ]);
        
        Response::created('Appointment booked successfully', [
            'appointment' => [
                'id' => $result['appointment_id'],
                'appointment_number' => $result['appointment_number'],
                'token_number' => $result['token_number'],
                'estimated_time' => $result['estimated_time'],
                'status' => 'pending',
                'appointment_date' => $request['appointment_date'],
                'doctor' => [
                    'id' => $doctor['id'],
                    'full_name' => $doctor['full_name'],
                    'specialty' => $doctor['specialty'],
                    'clinic_name' => $doctor['clinic_name'],
                    'clinic_address' => $doctor['clinic_address']
                ]
            ],
            'payment' => [
                'payment_number' => $paymentResult['payment_number'],
                'amount' => $doctor['consultation_fee'],
                'status' => 'pending'
            ]
        ]);
    }
    
    /**
     * Get appointment by ID
     * GET /api/appointments/{id}
     */
    public function show(int $id, array $user): void
    {
        $appointment = $this->appointmentModel->getFullDetails($id);
        
        if (!$appointment) {
            Response::notFound('Appointment');
        }
        
        // Check permission
        $patient = $this->patientModel->findByUserId($user['user_id']);
        $doctor = $this->doctorModel->findByUserId($user['user_id']);
        
        $isAuthorized = 
            ($patient && $appointment['patient_id'] == $patient['id']) ||
            ($doctor && $appointment['doctor_id'] == $doctor['id']) ||
            $user['role'] === 'admin';
        
        if (!$isAuthorized) {
            Response::forbidden('You do not have permission to view this appointment');
        }
        
        Response::success('Appointment retrieved', $appointment);
    }
    
    /**
     * Get patient's appointments
     * GET /api/appointments/my-appointments
     */
    public function myAppointments(array $request, array $user): void
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
        $status = $request['status'] ?? null;
        
        $result = $this->appointmentModel->getPatientAppointments($patient['id'], $status, $page, $perPage);
        
        Response::paginated(
            $result['items'],
            $result['total'],
            $result['page'],
            $result['per_page']
        );
    }

    /**
     * Create or update a patient review for a completed appointment
     * POST /api/appointments/{id}/review
     */
    public function saveReview(int $id, array $request, array $user): void
    {
        if ($user['role'] !== 'patient') {
            Response::forbidden('Only patients can rate doctors');
        }

        $validator = Validator::quick($request, [
            'rating' => 'required|integer|min:1|max:5',
            'review_text' => 'max:1000'
        ]);

        if (!$validator['valid']) {
            Response::validationError($validator['errors']);
        }

        $patient = $this->patientModel->findByUserId($user['user_id']);

        if (!$patient) {
            Response::notFound('Patient profile');
        }

        $appointment = $this->appointmentModel->find($id);

        if (!$appointment) {
            Response::notFound('Appointment');
        }

        if ((int) $appointment['patient_id'] !== (int) $patient['id']) {
            Response::forbidden('You can only review your own completed appointments');
        }

        if (($appointment['status'] ?? '') !== 'completed') {
            Response::error('You can rate a doctor only after a completed appointment');
        }

        $rating = (int) $request['rating'];
        $reviewText = isset($request['review_text']) ? trim((string) $request['review_text']) : null;

        $result = $this->reviewModel->upsertForAppointment(
            (int) $appointment['doctor_id'],
            (int) $patient['id'],
            $id,
            $rating,
            $reviewText
        );

        if (!$result['success']) {
            Response::error('Failed to save your review. Please try again.');
        }

        $this->doctorModel->updateRating((int) $appointment['doctor_id']);

        $review = $this->reviewModel->find((int) $result['id']);
        $doctor = $this->doctorModel->find((int) $appointment['doctor_id']);

        Response::success(
            $result['action'] === 'updated'
                ? 'Review updated successfully'
                : 'Review submitted successfully',
            [
                'review' => $review,
                'doctor' => [
                    'id' => (int) ($doctor['id'] ?? 0),
                    'average_rating' => isset($doctor['average_rating']) ? round((float) $doctor['average_rating'], 1) : 0.0,
                    'total_reviews' => (int) ($doctor['total_reviews'] ?? 0)
                ]
            ]
        );
    }
    
    /**
     * Get doctor's appointments
     * GET /api/appointments/doctor-appointments
     */
    public function doctorAppointments(array $request, array $user): void
    {
        if ($user['role'] !== 'doctor') {
            Response::forbidden('Only doctors can access this endpoint');
        }
        
        $doctor = $this->doctorModel->findByUserId($user['user_id']);
        
        if (!$doctor) {
            Response::notFound('Doctor profile');
        }
        
        $date = $request['date'] ?? null;
        $status = $request['status'] ?? null;
        
        $appointments = $this->appointmentModel->getDoctorAppointments($doctor['id'], $date, $status);
        
        Response::success('Appointments retrieved', $appointments);
    }
    
    /**
     * Get today's appointments for doctor
     * GET /api/appointments/today
     */
    public function todayAppointments(array $user): void
    {
        if ($user['role'] !== 'doctor') {
            Response::forbidden('Only doctors can access this endpoint');
        }
        
        $doctor = $this->doctorModel->findByUserId($user['user_id']);
        
        if (!$doctor) {
            Response::notFound('Doctor profile');
        }
        
        $appointments = $this->appointmentModel->getTodayAppointments($doctor['id']);
        
        Response::success('Today\'s appointments retrieved', $appointments);
    }
    
    /**
     * Update appointment status
     * PATCH /api/appointments/{id}/status
     */
    public function updateStatus(int $id, array $request, array $user): void
    {
        // Validate input
        $validator = Validator::quick($request, [
            'status' => 'required|in:pending,confirmed,in_progress,completed,cancelled,no_show'
        ]);
        
        if (!$validator['valid']) {
            Response::validationError($validator['errors']);
        }
        
        $appointment = $this->appointmentModel->find($id);
        
        if (!$appointment) {
            Response::notFound('Appointment');
        }
        
        // Check permissions based on status change
        $patient = $this->patientModel->findByUserId($user['user_id']);
        $doctor = $this->doctorModel->findByUserId($user['user_id']);
        
        $newStatus = $request['status'];
        
        // Patients can only cancel their own appointments
        if ($patient && $appointment['patient_id'] == $patient['id']) {
            if ($newStatus !== 'cancelled') {
                Response::forbidden('Patients can only cancel appointments');
            }
        }
        // Doctors can update status of their appointments
        elseif ($doctor && $appointment['doctor_id'] == $doctor['id']) {
            $allowedStatuses = ['confirmed', 'in_progress', 'completed', 'no_show', 'cancelled'];
            if (!in_array($newStatus, $allowedStatuses)) {
                Response::forbidden('Invalid status for doctor');
            }
        }
        // Admins can do anything
        elseif ($user['role'] !== 'admin') {
            Response::forbidden('You do not have permission to update this appointment');
        }
        
        // Additional validation for status changes
        if ($appointment['status'] === 'completed' && $newStatus !== 'completed') {
            Response::error('Cannot change status of completed appointment');
        }
        
        if ($appointment['status'] === 'cancelled') {
            Response::error('Cannot update cancelled appointment');
        }
        
        $additionalData = [];
        
        if ($newStatus === 'cancelled') {
            $additionalData['cancellation_reason'] = $request['reason'] ?? 'No reason provided';
            $additionalData['cancelled_by'] = $user['role'];
        }

        // Generate Jitsi video room when doctor confirms appointment
        if ($newStatus === 'confirmed' && empty($appointment['video_room_url'])) {
            $roomName = 'mediseba-' . $id . '-' . bin2hex(random_bytes(6));
            $additionalData['video_room_url']    = 'https://meet.jit.si/' . $roomName;
            $additionalData['video_call_enabled'] = 1;
        }
        
        $success = $this->appointmentModel->updateStatus($id, $newStatus, $additionalData);
        
        if ($success) {
            $responseData = [
                'appointment_id' => $id,
                'new_status'     => $newStatus,
            ];
            if (!empty($additionalData['video_room_url'])) {
                $responseData['video_room_url']    = $additionalData['video_room_url'];
                $responseData['video_call_enabled'] = 1;
            }
            Response::success('Appointment status updated successfully', $responseData);
        } else {
            Response::error('Failed to update appointment status');
        }
    }
    
    /**
     * Get video room details for an appointment
     * GET /api/appointments/{id}/video-room
     */
    public function videoRoom(int $id, array $user): void
    {
        $appointment = $this->appointmentModel->find($id);

        if (!$appointment) {
            Response::notFound('Appointment');
        }

        // Authorise: only the patient or doctor for this appointment
        $patient = $this->patientModel->findByUserId($user['user_id']);
        $doctor  = $this->doctorModel->findByUserId($user['user_id']);

        $isAuthorized =
            ($patient && (int) $appointment['patient_id'] === (int) $patient['id']) ||
            ($doctor  && (int) $appointment['doctor_id']  === (int) $doctor['id'])  ||
            $user['role'] === 'admin';

        if (!$isAuthorized) {
            Response::forbidden('You do not have access to this appointment');
        }

        if (empty($appointment['video_call_enabled']) || empty($appointment['video_room_url'])) {
            Response::error('Video call is not available for this appointment. The doctor must confirm the appointment first.', [], 404);
        }

        Response::success('Video room details retrieved', [
            'appointment_id'     => $id,
            'video_room_url'     => $appointment['video_room_url'],
            'video_call_enabled' => true,
            'status'             => $appointment['status'],
        ]);
    }

    /**
     * Cancel appointment
     * POST /api/appointments/{id}/cancel
     */
    public function cancel(int $id, array $request, array $user): void
    {
        $appointment = $this->appointmentModel->find($id);
        
        if (!$appointment) {
            Response::notFound('Appointment');
        }
        
        // Check permission
        $patient = $this->patientModel->findByUserId($user['user_id']);
        $doctor = $this->doctorModel->findByUserId($user['user_id']);
        
        $isAuthorized = 
            ($patient && $appointment['patient_id'] == $patient['id']) ||
            ($doctor && $appointment['doctor_id'] == $doctor['id']) ||
            $user['role'] === 'admin';
        
        if (!$isAuthorized) {
            Response::forbidden('You do not have permission to cancel this appointment');
        }
        
        $reason = $request['reason'] ?? 'Cancelled by ' . $user['role'];
        $cancelledBy = $patient ? 'patient' : ($doctor ? 'doctor' : 'admin');
        
        $result = $this->appointmentModel->cancel($id, $reason, $cancelledBy);
        
        if ($result['success']) {
            Response::success('Appointment cancelled successfully');
        } else {
            Response::error($result['message']);
        }
    }
    
    /**
     * Get upcoming appointments for patient
     * GET /api/appointments/upcoming
     */
    public function upcoming(array $user): void
    {
        if ($user['role'] !== 'patient') {
            Response::forbidden('Only patients can access this endpoint');
        }
        
        $patient = $this->patientModel->findByUserId($user['user_id']);
        
        if (!$patient) {
            Response::notFound('Patient profile');
        }
        
        $appointments = $this->appointmentModel->getUpcomingAppointments($patient['id'], 10);
        
        Response::success('Upcoming appointments retrieved', $appointments);
    }
    
    /**
     * Get appointment statistics
     * GET /api/appointments/statistics
     */
    public function statistics(array $user): void
    {
        $doctorId = null;
        $patientId = null;
        
        if ($user['role'] === 'doctor') {
            $doctor = $this->doctorModel->findByUserId($user['user_id']);
            if (!$doctor) {
                Response::notFound('Doctor profile');
            }
            $doctorId = $doctor['id'];
        } elseif ($user['role'] === 'patient') {
            $patient = $this->patientModel->findByUserId($user['user_id']);
            if (!$patient) {
                Response::forbidden('Invalid patient profile data access');
            }
            $patientId = $patient['id'];
        } else {
            // Only admin should get global stats
            if ($user['role'] !== 'admin') {
                Response::forbidden('Unauthorized to view global statistics');
            }
        }
        
        $stats = $this->appointmentModel->getStatistics($doctorId, $patientId);
        
        Response::success('Statistics retrieved', $stats);
    }
}
