<?php
/**
 * MediSeba - Prescription Controller
 * 
 * Handles prescription creation and management
 */

declare(strict_types=1);

namespace MediSeba\Controllers;

use MediSeba\Utils\Response;
use MediSeba\Utils\SimplePdfDocument;
use MediSeba\Utils\Validator;
use MediSeba\Models\Prescription;
use MediSeba\Models\Appointment;
use MediSeba\Models\DoctorProfile;
use MediSeba\Models\PatientProfile;

class PrescriptionController
{
    private Prescription $prescriptionModel;
    private Appointment $appointmentModel;
    private DoctorProfile $doctorModel;
    private PatientProfile $patientModel;
    
    public function __construct()
    {
        $this->prescriptionModel = new Prescription();
        $this->appointmentModel = new Appointment();
        $this->doctorModel = new DoctorProfile();
        $this->patientModel = new PatientProfile();
    }
    
    /**
     * Create new prescription
     * POST /api/prescriptions
     */
    public function store(array $request, array $user): void
    {
        // Verify user is a doctor
        if ($user['role'] !== 'doctor') {
            Response::forbidden('Only doctors can create prescriptions');
        }
        
        // Validate input
        $validator = Validator::quick($request, [
            'appointment_id' => 'required|integer',
            'symptoms' => 'required|max:2000',
            'diagnosis' => 'required|max:2000',
            'medicine_list' => 'required|array',
            'dosage_instructions' => 'max:2000',
            'advice' => 'max:2000',
            'follow_up_date' => 'date|future'
        ]);
        
        if (!$validator['valid']) {
            Response::validationError($validator['errors']);
        }
        
        // Get doctor profile
        $doctor = $this->doctorModel->findByUserId($user['user_id']);
        
        if (!$doctor) {
            Response::notFound('Doctor profile');
        }
        
        // Get appointment
        $appointment = $this->appointmentModel->find($request['appointment_id']);
        
        if (!$appointment) {
            Response::notFound('Appointment');
        }
        
        // Verify doctor owns this appointment
        if ($appointment['doctor_id'] !== $doctor['id']) {
            Response::forbidden('You can only create prescriptions for your own appointments');
        }
        
        // Check if appointment is completed
        if ($appointment['status'] !== 'completed') {
            Response::error('Can only create prescriptions for completed appointments');
        }
        
        // Create prescription
        $result = $this->prescriptionModel->createPrescription([
            'appointment_id' => $request['appointment_id'],
            'patient_id' => $appointment['patient_id'],
            'doctor_id' => $doctor['id'],
            'symptoms' => $request['symptoms'],
            'diagnosis' => $request['diagnosis'],
            'diagnosis_codes' => $request['diagnosis_codes'] ?? null,
            'medicine_list' => $request['medicine_list'],
            'dosage_instructions' => $request['dosage_instructions'] ?? null,
            'advice' => $request['advice'] ?? null,
            'follow_up_date' => $request['follow_up_date'] ?? null,
            'follow_up_notes' => $request['follow_up_notes'] ?? null
        ]);
        
        if ($result['success']) {
            Response::created('Prescription created successfully', $result);
        } else {
            Response::error($result['message']);
        }
    }
    
    /**
     * Get prescription by ID
     * GET /api/prescriptions/{id}
     */
    public function show(int $id, array $user): void
    {
        $prescription = $this->getAuthorizedPrescriptionOrFail($id, $user);
        Response::success('Prescription retrieved', $prescription);
    }
    
    /**
     * Get prescription by number
     * GET /api/prescriptions/number/{number}
     */
    public function showByNumber(string $number, array $user): void
    {
        $prescription = $this->prescriptionModel->findByNumber($number);
        
        if (!$prescription) {
            Response::notFound('Prescription');
        }
        
        // Check permission
        $patient = $this->patientModel->findByUserId($user['user_id']);
        $doctor = $this->doctorModel->findByUserId($user['user_id']);
        
        $isAuthorized = 
            ($patient && $prescription['patient_id'] == $patient['id']) ||
            ($doctor && $prescription['doctor_id'] == $doctor['id']) ||
            $user['role'] === 'admin';
        
        if (!$isAuthorized) {
            Response::forbidden('You do not have permission to view this prescription');
        }
        
        // Get full details
        $fullDetails = $this->prescriptionModel->getFullDetails($prescription['id']);
        
        Response::success('Prescription retrieved', $fullDetails);
    }

    /**
     * Download prescription PDF
     * GET /api/prescriptions/{id}/pdf
     */
    public function downloadPdf(int $id, array $user): void
    {
        $prescription = $this->getAuthorizedPrescriptionOrFail($id, $user);
        $pdf = $this->buildPrescriptionPdf($prescription);
        $filename = 'prescription-' . ($prescription['prescription_number'] ?? ('rx-' . $id)) . '.pdf';

        Response::download($pdf, $filename, 'application/pdf');
    }
    
    /**
     * Get patient's prescriptions
     * GET /api/prescriptions/my-prescriptions
     */
    public function myPrescriptions(array $request, array $user): void
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
        
        $result = $this->prescriptionModel->getPatientPrescriptions($patient['id'], $page, $perPage);
        
        Response::paginated(
            $result['items'],
            $result['total'],
            $result['page'],
            $result['per_page']
        );
    }
    
    /**
     * Get doctor's prescriptions
     * GET /api/prescriptions/doctor-prescriptions
     */
    public function doctorPrescriptions(array $request, array $user): void
    {
        if ($user['role'] !== 'doctor') {
            Response::forbidden('Only doctors can access this endpoint');
        }
        
        $doctor = $this->doctorModel->findByUserId($user['user_id']);
        
        if (!$doctor) {
            Response::notFound('Doctor profile');
        }
        
        $page = (int) ($request['page'] ?? 1);
        $perPage = min((int) ($request['per_page'] ?? 20), 50);
        
        $result = $this->prescriptionModel->getDoctorPrescriptions($doctor['id'], $page, $perPage);
        
        Response::paginated(
            $result['items'],
            $result['total'],
            $result['page'],
            $result['per_page']
        );
    }
    
    /**
     * Update prescription
     * PUT /api/prescriptions/{id}
     */
    public function update(int $id, array $request, array $user): void
    {
        // Verify user is a doctor
        if ($user['role'] !== 'doctor') {
            Response::forbidden('Only doctors can update prescriptions');
        }
        
        $prescription = $this->prescriptionModel->find($id);
        
        if (!$prescription) {
            Response::notFound('Prescription');
        }
        
        // Get doctor profile
        $doctor = $this->doctorModel->findByUserId($user['user_id']);
        
        // Verify doctor owns this prescription
        if ($prescription['doctor_id'] !== $doctor['id']) {
            Response::forbidden('You can only update your own prescriptions');
        }
        
        // Validate input
        $validator = Validator::quick($request, [
            'symptoms' => 'max:2000',
            'diagnosis' => 'max:2000',
            'medicine_list' => 'array',
            'dosage_instructions' => 'max:2000',
            'advice' => 'max:2000',
            'follow_up_date' => 'date|future'
        ]);
        
        if (!$validator['valid']) {
            Response::validationError($validator['errors']);
        }
        
        // Filter allowed fields
        $allowedFields = [
            'symptoms', 'diagnosis', 'diagnosis_codes', 'medicine_list',
            'dosage_instructions', 'advice', 'follow_up_date', 'follow_up_notes'
        ];
        
        $updateData = array_intersect_key($request, array_flip($allowedFields));
        
        $success = $this->prescriptionModel->updatePrescription($id, $updateData);
        
        if ($success) {
            $updated = $this->prescriptionModel->getFullDetails($id);
            Response::success('Prescription updated successfully', $updated);
        } else {
            Response::error('Failed to update prescription');
        }
    }
    
    /**
     * Delete prescription (soft delete)
     * DELETE /api/prescriptions/{id}
     */
    public function delete(int $id, array $user): void
    {
        // Verify user is a doctor or admin
        if (!in_array($user['role'], ['doctor', 'admin'], true)) {
            Response::forbidden('Only doctors and admins can delete prescriptions');
        }
        
        $prescription = $this->prescriptionModel->find($id);
        
        if (!$prescription) {
            Response::notFound('Prescription');
        }
        
        // If doctor, verify ownership
        if ($user['role'] === 'doctor') {
            $doctor = $this->doctorModel->findByUserId($user['user_id']);
            
            if ($prescription['doctor_id'] !== $doctor['id']) {
                Response::forbidden('You can only delete your own prescriptions');
            }
        }
        
        $success = $this->prescriptionModel->softDelete($id, $user['user_id']);
        
        if ($success) {
            Response::success('Prescription deleted successfully');
        } else {
            Response::error('Failed to delete prescription');
        }
    }
    
    /**
     * Search prescriptions
     * GET /api/prescriptions/search
     */
    public function search(array $request, array $user): void
    {
        if ($user['role'] !== 'patient') {
            Response::forbidden('Only patients can search their prescriptions');
        }
        
        $patient = $this->patientModel->findByUserId($user['user_id']);
        
        if (!$patient) {
            Response::notFound('Patient profile');
        }
        
        $query = $request['q'] ?? '';
        
        if (strlen($query) < 2) {
            Response::validationError(['q' => 'Search query must be at least 2 characters']);
        }
        
        $results = $this->prescriptionModel->search($patient['id'], $query);
        
        Response::success('Search results', $results);
    }
    
    /**
     * Get upcoming follow-ups
     * GET /api/prescriptions/follow-ups
     */
    public function followUps(array $request, array $user): void
    {
        $doctorId = null;
        
        if ($user['role'] === 'doctor') {
            $doctor = $this->doctorModel->findByUserId($user['user_id']);
            $doctorId = $doctor['id'] ?? null;
        }
        
        $days = min((int) ($request['days'] ?? 7), 30);
        
        $followUps = $this->prescriptionModel->getUpcomingFollowUps($doctorId, $days);
        
        Response::success('Upcoming follow-ups', $followUps);
    }

    private function getAuthorizedPrescriptionOrFail(int $id, array $user): array
    {
        $prescription = $this->prescriptionModel->getFullDetails($id);

        if (!$prescription) {
            Response::notFound('Prescription');
        }

        $patient = $this->patientModel->findByUserId($user['user_id']);
        $doctor = $this->doctorModel->findByUserId($user['user_id']);

        $isAuthorized =
            ($patient && (int) $prescription['patient_id'] === (int) $patient['id']) ||
            ($doctor && (int) $prescription['doctor_id'] === (int) $doctor['id']) ||
            $user['role'] === 'admin';

        if (!$isAuthorized) {
            Response::forbidden('You do not have permission to view this prescription');
        }

        return $prescription;
    }

    private function buildPrescriptionPdf(array $prescription): string
    {
        $document = new SimplePdfDocument('Prescription ' . ($prescription['prescription_number'] ?? ''));
        $document->addTitle('MediSeba Prescription');

        $document->addMetaLine('Prescription Number', $this->stringOrFallback($prescription['prescription_number'] ?? null));
        $document->addMetaLine('Issued On', $this->formatDateTime($prescription['created_at'] ?? null));
        $document->addMetaLine('Appointment Number', $this->stringOrFallback($prescription['appointment_number'] ?? null));
        $document->addMetaLine('Appointment Date', $this->formatDate($prescription['appointment_date'] ?? null));

        $document->addSection('Doctor');
        $document->addMetaLine('Name', $this->stringOrFallback($prescription['doctor_name'] ?? null));
        $document->addMetaLine('Specialty', $this->stringOrFallback($prescription['specialty'] ?? null));
        $document->addMetaLine('Qualification', $this->stringOrFallback($prescription['qualification'] ?? null));
        $document->addMetaLine('Registration', $this->stringOrFallback($prescription['registration_number'] ?? null));
        $document->addMetaLine('Clinic', $this->stringOrFallback($prescription['clinic_name'] ?? null));
        $document->addMetaLine('Clinic Address', $this->stringOrFallback($prescription['clinic_address'] ?? null));

        $document->addSection('Patient');
        $document->addMetaLine('Name', $this->stringOrFallback($prescription['patient_name'] ?? null));
        $document->addMetaLine('Email', $this->stringOrFallback($prescription['patient_email'] ?? null));
        $document->addMetaLine('Date of Birth', $this->formatDate($prescription['date_of_birth'] ?? null));
        $document->addMetaLine('Gender', $this->stringOrFallback($prescription['gender'] ?? null));
        $document->addMetaLine('Blood Group', $this->stringOrFallback($prescription['blood_group'] ?? null));

        $document->addSection('Symptoms');
        $document->addParagraph($this->stringOrFallback($prescription['symptoms'] ?? $prescription['appointment_symptoms'] ?? null));

        $document->addSection('Diagnosis');
        $document->addParagraph($this->stringOrFallback($prescription['diagnosis'] ?? null));

        $document->addSection('Medicines');
        $document->addBulletList($this->formatMedicineLines($prescription['medicine_list'] ?? []));

        $document->addSection('Dosage Instructions');
        $document->addParagraph($this->stringOrFallback($prescription['dosage_instructions'] ?? null));

        $document->addSection('Advice');
        $document->addParagraph($this->stringOrFallback($prescription['advice'] ?? null));

        $document->addSection('Follow-up');
        $document->addMetaLine('Follow-up Date', $this->formatDate($prescription['follow_up_date'] ?? null));
        $document->addParagraph($this->stringOrFallback($prescription['follow_up_notes'] ?? null, 'No follow-up note added.'));

        $document->addSpacer(8);
        $document->addParagraph('This prescription was generated digitally by MediSeba.', 10);

        return $document->output();
    }

    private function formatMedicineLines(mixed $medicineList): array
    {
        if (!is_array($medicineList)) {
            return [];
        }

        $lines = [];

        foreach ($medicineList as $item) {
            if (is_string($item)) {
                $lines[] = $item;
                continue;
            }

            if (!is_array($item)) {
                continue;
            }

            $name = trim((string) ($item['name'] ?? 'Medicine'));
            $details = array_filter([
                trim((string) ($item['dosage'] ?? '')),
                trim((string) ($item['strength'] ?? '')),
                trim((string) ($item['instructions'] ?? ''))
            ]);

            $lines[] = $details ? $name . ' - ' . implode(', ', $details) : $name;
        }

        return $lines;
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
