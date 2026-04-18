<?php
/**
 * MediSeba - Doctor Controller
 * 
 * Handles doctor search, profiles, and schedules
 */

declare(strict_types=1);

namespace MediSeba\Controllers;

use MediSeba\Utils\Response;
use MediSeba\Utils\Validator;
use MediSeba\Models\DoctorProfile;
use MediSeba\Config\Database;

class DoctorController
{
    private DoctorProfile $doctorModel;
    
    public function __construct()
    {
        $this->doctorModel = new DoctorProfile();
    }
    
    /**
     * List all doctors with filters
     * GET /api/doctors
     */
    public function index(array $request): void
    {
        $page = (int) ($request['page'] ?? 1);
        $perPage = min((int) ($request['per_page'] ?? 20), 50);
        
        $filters = [
            'name' => $request['name'] ?? null,
            'specialty' => $request['specialty'] ?? null,
            'min_experience' => $request['min_experience'] ?? null,
            'max_fee' => $request['max_fee'] ?? null,
            'featured' => isset($request['featured']) ? true : null,
            'sort' => $request['sort'] ?? 'created_at',
            'order' => $request['order'] ?? 'DESC'
        ];
        
        // Remove null values
        $filters = array_filter($filters);
        
        $result = $this->doctorModel->search($filters, $page, $perPage);
        
        Response::paginated(
            $result['items'],
            $result['total'],
            $result['page'],
            $result['per_page']
        );
    }
    
    /**
     * Get featured doctors
     * GET /api/doctors/featured
     */
    public function featured(): void
    {
        $doctors = $this->doctorModel->getFeatured(6);
        
        Response::success('Featured doctors retrieved', $doctors);
    }

    /**
     * Get visible testimonials based on real patient reviews.
     * GET /api/doctors/testimonials
     */
    public function testimonials(array $request): void
    {
        $limit = min((int) ($request['limit'] ?? 6), 12);
        $testimonials = $this->doctorModel->getVisibleTestimonials($limit);

        Response::success('Testimonials retrieved', $testimonials);
    }

    /**
     * Get public homepage statistics.
     * GET /api/doctors/public-stats
     */
    public function publicStats(): void
    {
        $stats = $this->doctorModel->getPublicStats();

        Response::success('Public statistics retrieved', $stats);
    }
    
    /**
     * Get doctor by ID
     * GET /api/doctors/{id}
     */
    public function show(int $id): void
    {
        $doctor = $this->doctorModel->find($id);
        
        if (!$doctor || !$doctor['is_verified']) {
            Response::notFound('Doctor');
        }
        
        // Get schedule
        $schedule = $this->doctorModel->getSchedule($id);
        
        // Get available dates
        $availableDates = $this->doctorModel->getAvailableDates($id, 14);
        
        Response::success('Doctor profile retrieved', [
            'doctor' => $doctor,
            'schedule' => $schedule,
            'available_dates' => $availableDates
        ]);
    }
    
    /**
     * Get doctor by slug
     * GET /api/doctors/slug/{slug}
     */
    public function showBySlug(string $slug): void
    {
        $doctor = $this->doctorModel->findBySlug($slug);
        
        if (!$doctor || !$doctor['is_verified']) {
            Response::notFound('Doctor');
        }
        
        // Get schedule
        $schedule = $this->doctorModel->getSchedule($doctor['id']);
        
        // Get available dates
        $availableDates = $this->doctorModel->getAvailableDates($doctor['id'], 14);
        
        Response::success('Doctor profile retrieved', [
            'doctor' => $doctor,
            'schedule' => $schedule,
            'available_dates' => $availableDates
        ]);
    }
    
    /**
     * Get all specialties
     * GET /api/doctors/specialties
     */
    public function specialties(): void
    {
        $specialties = $this->doctorModel->getSpecialties();
        
        Response::success('Specialties retrieved', $specialties);
    }
    
    /**
     * Get doctor schedule
     * GET /api/doctors/{id}/schedule
     */
    public function schedule(int $id): void
    {
        $doctor = $this->doctorModel->find($id);
        
        if (!$doctor) {
            Response::notFound('Doctor');
        }
        
        $schedule = $this->doctorModel->getSchedule($id);
        
        Response::success('Schedule retrieved', [
            'doctor_id' => $id,
            'schedule' => $schedule
        ]);
    }
    
    /**
     * Get available dates for doctor
     * GET /api/doctors/{id}/available-dates
     */
    public function availableDates(int $id, array $request): void
    {
        $doctor = $this->doctorModel->find($id);
        
        if (!$doctor) {
            Response::notFound('Doctor');
        }
        
        $days = min((int) ($request['days'] ?? 30), 60);
        
        $dates = $this->doctorModel->getAvailableDates($id, $days);
        
        Response::success('Available dates retrieved', [
            'doctor_id' => $id,
            'available_dates' => $dates
        ]);
    }
    
    /**
     * Get doctor statistics (for doctor dashboard)
     * GET /api/doctors/statistics
     */
    public function statistics(array $user): void
    {
        // Verify user is a doctor
        if ($user['role'] !== 'doctor') {
            Response::forbidden('Only doctors can access this endpoint');
        }
        
        $doctorModel = new DoctorProfile();
        $doctor = $doctorModel->findByUserId($user['user_id']);
        
        if (!$doctor) {
            Response::notFound('Doctor profile');
        }
        
        $stats = $doctorModel->getStatistics($doctor['id']);
        
        Response::success('Statistics retrieved', $stats);
    }
    
    /**
     * Show doctor's own profile
     * GET /api/doctors/profile
     */
    public function showProfile(array $user): void
    {
        $doctor = $this->doctorModel->findByUserId($user['user_id']);
        
        if (!$doctor) {
            Response::notFound('Doctor profile');
            return;
        }
        
        $schedule = $this->doctorModel->getSchedule($doctor['id']);
        
        Response::success('Doctor profile retrieved', [
            'doctor' => $doctor,
            'schedule' => $schedule
        ]);
    }
    
    /**
     * Update doctor profile (for doctors)
     * PUT /api/doctors/profile
     */
    public function updateProfile(array $request, array $user): void
    {
        // Verify user is a doctor
        if ($user['role'] !== 'doctor') {
            Response::forbidden('Only doctors can update their profile');
        }
        
        $doctor = $this->doctorModel->findByUserId($user['user_id']);
        
        if (!$doctor) {
            Response::notFound('Doctor profile');
        }

        if (array_key_exists('registration_number', $request)) {
            $registrationNumber = trim((string) $request['registration_number']);
            $request['registration_number'] = $registrationNumber === '' ? null : $registrationNumber;

            if ($request['registration_number'] !== null && strlen($request['registration_number']) > 100) {
                Response::validationError([
                    'registration_number' => 'The registration_number must not exceed 100 characters.'
                ]);
            }
        }
        
        // Validate input
        $validator = Validator::quick($request, [
            'full_name' => 'min:3|max:100',
            'qualification' => 'max:500',
            'experience_years' => 'integer|min:0|max:70',
            'consultation_fee' => 'numeric|min:0',
            'clinic_name' => 'max:255',
            'clinic_address' => 'max:500',
            'bio' => 'max:2000'
        ]);
        
        if (!$validator['valid']) {
            Response::validationError($validator['errors']);
        }
        
        // Filter allowed fields
        $allowedFields = [
            'full_name', 'registration_number', 'qualification', 'experience_years', 'consultation_fee',
            'clinic_name', 'clinic_address', 'bio', 'languages'
        ];
        
        $updateData = array_intersect_key($request, array_flip($allowedFields));
        
        $success = $this->doctorModel->update($doctor['id'], $updateData);
        
        if ($success) {
            $updatedDoctor = $this->doctorModel->find($doctor['id']);
            Response::success('Profile updated successfully', ['doctor' => $updatedDoctor]);
        } else {
            Response::error('Failed to update profile');
        }
    }
    
    /**
     * Update doctor schedule (for doctors)
     * PUT /api/doctors/schedule
     */
    public function updateSchedule(array $request, array $user): void
    {
        // Verify user is a doctor
        if ($user['role'] !== 'doctor') {
            Response::forbidden('Only doctors can update their schedule');
        }
        
        $doctor = $this->doctorModel->findByUserId($user['user_id']);
        
        if (!$doctor) {
            Response::notFound('Doctor profile');
        }
        
        // Validate schedule data
        if (empty($request['schedule']) || !is_array($request['schedule'])) {
            Response::validationError(['schedule' => 'Schedule data is required']);
        }

        $normalizedSchedule = $this->normalizeSchedulePayload($request['schedule']);
        
        $db = Database::getConnection();
        
        try {
            $db->beginTransaction();

            $existingStmt = $db->prepare(
                "SELECT ds.*,
                        EXISTS(
                            SELECT 1
                            FROM appointments a
                            WHERE a.schedule_id = ds.id
                            LIMIT 1
                        ) AS has_appointments
                 FROM doctor_schedules ds
                 WHERE ds.doctor_id = ?
                 ORDER BY ds.weekday, ds.is_available DESC, ds.id ASC"
            );
            $existingStmt->execute([$doctor['id']]);
            $existingSchedules = $existingStmt->fetchAll();

            $existingByWeekday = [];
            foreach ($existingSchedules as $scheduleRow) {
                $weekday = (int) $scheduleRow['weekday'];
                $existingByWeekday[$weekday] ??= [];
                $existingByWeekday[$weekday][] = $scheduleRow;
            }

            $updateStmt = $db->prepare(
                "UPDATE doctor_schedules
                 SET weekday = ?, start_time = ?, end_time = ?, slot_duration = ?, max_patients = ?, is_available = ?
                 WHERE id = ?"
            );
            $insertStmt = $db->prepare("INSERT INTO doctor_schedules 
                (doctor_id, weekday, start_time, end_time, slot_duration, max_patients, is_available) 
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $deleteStmt = $db->prepare("DELETE FROM doctor_schedules WHERE id = ?");

            foreach ($normalizedSchedule as $slot) {
                $weekday = $slot['weekday'];
                $existingSlot = null;

                if (!empty($existingByWeekday[$weekday])) {
                    $existingSlot = array_shift($existingByWeekday[$weekday]);
                }

                if ($existingSlot) {
                    $updateStmt->execute([
                        $slot['weekday'],
                        $slot['start_time'],
                        $slot['end_time'],
                        $slot['slot_duration'],
                        $slot['max_patients'],
                        $slot['is_available'],
                        $existingSlot['id']
                    ]);
                } else {
                    $insertStmt->execute([
                        $doctor['id'],
                        $slot['weekday'],
                        $slot['start_time'],
                        $slot['end_time'],
                        $slot['slot_duration'],
                        $slot['max_patients'],
                        $slot['is_available']
                    ]);
                }
            }

            foreach ($existingByWeekday as $leftoverSlots) {
                foreach ($leftoverSlots as $leftoverSlot) {
                    if ((int) $leftoverSlot['has_appointments'] > 0) {
                        $updateStmt->execute([
                            (int) $leftoverSlot['weekday'],
                            $leftoverSlot['start_time'],
                            $leftoverSlot['end_time'],
                            (int) ($leftoverSlot['slot_duration'] ?? 15),
                            (int) ($leftoverSlot['max_patients'] ?? 10),
                            0,
                            $leftoverSlot['id']
                        ]);
                    } else {
                        $deleteStmt->execute([$leftoverSlot['id']]);
                    }
                }
            }
            
            $db->commit();
            
            $updatedSchedule = $this->doctorModel->getSchedule($doctor['id']);
            Response::success('Schedule updated successfully', ['schedule' => $updatedSchedule]);
            
        } catch (\PDOException $e) {
            $db->rollBack();
            error_log('Doctor schedule update failed: ' . $e->getMessage());
            Response::error('Failed to update schedule');
        }
    }

    /**
     * Normalize and validate incoming schedule rows.
     */
    private function normalizeSchedulePayload(array $schedule): array
    {
        $normalized = [];
        $seenWeekdays = [];

        foreach ($schedule as $slot) {
            $weekday = isset($slot['weekday']) ? (int) $slot['weekday'] : -1;

            if ($weekday < 0 || $weekday > 6) {
                Response::validationError(['schedule' => 'Weekday must be between 0 and 6.']);
            }

            if (isset($seenWeekdays[$weekday])) {
                Response::validationError(['schedule' => 'Only one active slot per weekday can be saved from this editor.']);
            }

            $startTime = $this->normalizeTimeValue((string) ($slot['start_time'] ?? ''));
            $endTime = $this->normalizeTimeValue((string) ($slot['end_time'] ?? ''));
            $slotDuration = max(5, (int) ($slot['slot_duration'] ?? 15));
            $maxPatients = max(1, (int) ($slot['max_patients'] ?? 10));

            if ($startTime >= $endTime) {
                Response::validationError(['schedule' => 'End time must be later than start time for each active day.']);
            }

            $normalized[] = [
                'weekday' => $weekday,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'slot_duration' => $slotDuration,
                'max_patients' => $maxPatients,
                'is_available' => !empty($slot['is_available']) ? 1 : 0
            ];

            $seenWeekdays[$weekday] = true;
        }

        return $normalized;
    }

    /**
     * Accept HH:MM or HH:MM:SS and store as TIME-compatible HH:MM:SS.
     */
    private function normalizeTimeValue(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^\d{2}:\d{2}$/', $value) === 1) {
            return $value . ':00';
        }

        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $value) === 1) {
            return $value;
        }

        Response::validationError(['schedule' => 'Please provide a valid time for each active day.']);
    }
}
