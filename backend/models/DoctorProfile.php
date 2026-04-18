<?php
/**
 * MediSeba - Doctor Profile Model
 * 
 * Handles doctor profile management and search
 */

declare(strict_types=1);

namespace MediSeba\Models;

class DoctorProfile extends Model
{
    protected string $table = 'doctor_profiles';
    protected string $primaryKey = 'id';
    
    protected array $fillable = [
        'user_id',
        'full_name',
        'slug',
        'specialty',
        'qualification',
        'experience_years',
        'consultation_fee',
        'clinic_name',
        'clinic_address',
        'clinic_latitude',
        'clinic_longitude',
        'profile_photo',
        'bio',
        'languages',
        'registration_number',
        'is_verified',
        'is_featured',
        'average_rating',
        'total_reviews',
        'total_appointments'
    ];
    
    protected array $casts = [
        'id' => 'int',
        'user_id' => 'int',
        'experience_years' => 'int',
        'consultation_fee' => 'float',
        'clinic_latitude' => 'float',
        'clinic_longitude' => 'float',
        'languages' => 'array',
        'is_verified' => 'bool',
        'is_featured' => 'bool',
        'average_rating' => 'float',
        'total_reviews' => 'int',
        'total_appointments' => 'int',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];
    
    /**
     * Find doctor by user ID
     */
    public function findByUserId(int $userId): ?array
    {
        return $this->findBy('user_id', $userId);
    }

    /**
     * Create or update doctor profile by user ID
     */
    public function createOrUpdate(int $userId, array $data): array
    {
        $existing = $this->findByUserId($userId);

        // Filter out null values so we don't overwrite existing data with nulls
        $data = array_filter($data, fn($v) => $v !== null);

        if ($existing) {
            $success = $this->update($existing['id'], $data);
            return [
                'success'    => $success,
                'profile_id' => $existing['id'],
                'message'    => $success ? 'Profile updated successfully' : 'Failed to update profile'
            ];
        }

        // New profile — ensure required fields have fallbacks
        $data['user_id']    = $userId;
        $data['full_name']  = $data['full_name'] ?? 'Doctor';
        $data['slug']       = $this->generateSlug($data['full_name']);
        $data['specialty']  = $data['specialty'] ?? 'General Physician';
        $data['qualification'] = $data['qualification'] ?? 'MBBS';

        $id = $this->create($data);
        return [
            'success'    => true,
            'profile_id' => $id,
            'message'    => 'Profile created successfully'
        ];
    }
    
    /**
     * Find doctor by slug
     */
    public function findBySlug(string $slug): ?array
    {
        return $this->findBy('slug', $slug);
    }
    
    /**
     * Search doctors with filters
     */
    public function search(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where = ['is_verified = 1'];
        $params = [];
        
        // Filter by specialty
        if (!empty($filters['specialty'])) {
            $where[] = 'specialty = ?';
            $params[] = $filters['specialty'];
        }
        
        // Filter by name search
        if (!empty($filters['name'])) {
            $where[] = '(full_name LIKE ? OR specialty LIKE ?)';
            $params[] = "%{$filters['name']}%";
            $params[] = "%{$filters['name']}%";
        }
        
        // Filter by minimum experience
        if (!empty($filters['min_experience'])) {
            $where[] = 'experience_years >= ?';
            $params[] = (int) $filters['min_experience'];
        }
        
        // Filter by maximum fee
        if (!empty($filters['max_fee'])) {
            $where[] = 'consultation_fee <= ?';
            $params[] = (float) $filters['max_fee'];
        }
        
        // Filter by featured
        if (!empty($filters['featured'])) {
            $where[] = 'is_featured = 1';
        }
        
        $whereClause = implode(' AND ', $where);
        
        // Get total count
        $countSql = "SELECT COUNT(*) FROM {$this->table} WHERE {$whereClause}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        
        // Get paginated results
        $offset = ($page - 1) * $perPage;
        
        $orderBy = $filters['sort'] ?? 'created_at';
        $direction = strtoupper($filters['order'] ?? 'DESC');
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            $direction = 'DESC';
        }
        
        $allowedSort = ['created_at', 'consultation_fee', 'experience_years', 'average_rating', 'full_name'];
        if (!in_array($orderBy, $allowedSort, true)) {
            $orderBy = 'created_at';
        }
        
        $orderExpression = $orderBy;

        if ($orderBy === 'average_rating') {
            $orderExpression = 'CASE WHEN total_reviews > 0 THEN average_rating ELSE 0 END';
        }

        $sql = "SELECT * FROM {$this->table} WHERE {$whereClause} ORDER BY is_featured DESC, {$orderExpression} {$direction}, total_reviews DESC, created_at DESC LIMIT ? OFFSET ?";
        $params[] = $perPage;
        $params[] = $offset;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll();
        
        return [
            'items' => array_map([$this, 'processResult'], $results),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage)
        ];
    }
    
    /**
     * Get all specialties
     */
    public function getSpecialties(): array
    {
        $sql = "SELECT DISTINCT specialty, COUNT(*) as doctor_count 
                FROM {$this->table} 
                WHERE is_verified = 1 
                GROUP BY specialty 
                ORDER BY doctor_count DESC";
        
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll();
    }
    
    /**
     * Get featured doctors
     */
    public function getFeatured(int $limit = 6): array
    {
        $sql = "SELECT * FROM {$this->table} 
                WHERE is_verified = 1 AND is_featured = 1 
                ORDER BY CASE WHEN total_reviews > 0 THEN average_rating ELSE 0 END DESC, total_reviews DESC, total_appointments DESC 
                LIMIT ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$limit]);
        $featured = $stmt->fetchAll();

        if (empty($featured)) {
            $fallbackSql = "SELECT * FROM {$this->table}
                            WHERE is_verified = 1
                            ORDER BY CASE WHEN total_reviews > 0 THEN average_rating ELSE 0 END DESC, total_reviews DESC, total_appointments DESC, created_at DESC
                            LIMIT ?";
            $fallbackStmt = $this->db->prepare($fallbackSql);
            $fallbackStmt->execute([$limit]);
            $featured = $fallbackStmt->fetchAll();
        }

        return array_map([$this, 'processResult'], $featured);
    }

    /**
     * Get latest visible patient testimonials with real review text.
     */
    public function getVisibleTestimonials(int $limit = 6): array
    {
        $limit = max(1, min($limit, 12));

        $sql = "SELECT
                    dr.id,
                    dr.rating,
                    dr.review_text,
                    dr.created_at,
                    dp.id AS doctor_id,
                    dp.full_name AS doctor_name,
                    dp.specialty,
                    pp.full_name AS patient_name,
                    pp.profile_photo AS patient_profile_photo
                FROM doctor_reviews dr
                INNER JOIN doctor_profiles dp ON dp.id = dr.doctor_id
                INNER JOIN patient_profiles pp ON pp.id = dr.patient_id
                WHERE dr.is_visible = 1
                  AND dp.is_verified = 1
                  AND dr.review_text IS NOT NULL
                  AND TRIM(dr.review_text) <> ''
                ORDER BY dr.created_at DESC
                LIMIT ?";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$limit]);
        $reviews = $stmt->fetchAll();

        return array_map(function (array $review): array {
            return [
                'id' => (int) $review['id'],
                'rating' => (int) $review['rating'],
                'quote' => (string) $review['review_text'],
                'created_at' => $review['created_at'],
                'doctor_id' => (int) $review['doctor_id'],
                'doctor_name' => (string) $review['doctor_name'],
                'specialty' => (string) $review['specialty'],
                'patient_name' => $this->maskPatientName((string) $review['patient_name']),
                'patient_initials' => $this->buildPatientInitials((string) $review['patient_name']),
                'patient_profile_photo' => !empty($review['patient_profile_photo'])
                    ? (string) $review['patient_profile_photo']
                    : null
            ];
        }, $reviews);
    }

    /**
     * Get public platform statistics for the homepage.
     */
    public function getPublicStats(): array
    {
        $sql = "SELECT
                    (SELECT COUNT(*) FROM patient_profiles) AS total_patients,
                    (SELECT COUNT(*) FROM {$this->table} WHERE is_verified = 1) AS verified_doctors,
                    (SELECT COUNT(*) FROM appointments WHERE status NOT IN ('cancelled', 'no_show')) AS total_appointments,
                    (SELECT ROUND(AVG(rating), 1) FROM doctor_reviews WHERE is_visible = 1) AS average_rating,
                    (SELECT COUNT(*) FROM doctor_reviews WHERE is_visible = 1) AS total_reviews";

        $stmt = $this->db->query($sql);
        $stats = $stmt->fetch() ?: [];

        return [
            'total_patients' => (int) ($stats['total_patients'] ?? 0),
            'verified_doctors' => (int) ($stats['verified_doctors'] ?? 0),
            'total_appointments' => (int) ($stats['total_appointments'] ?? 0),
            'average_rating' => isset($stats['average_rating']) && $stats['average_rating'] !== null
                ? round((float) $stats['average_rating'], 1)
                : 0.0,
            'total_reviews' => (int) ($stats['total_reviews'] ?? 0)
        ];
    }

    private function maskPatientName(string $fullName): string
    {
        $parts = $this->normalizePatientNameParts($fullName);

        if (count($parts) === 0) {
            return 'Verified Patient';
        }

        if (count($parts) === 1) {
            return $parts[0];
        }

        $last = end($parts);

        return $parts[0] . ' ' . strtoupper(substr((string) $last, 0, 1)) . '.';
    }

    private function buildPatientInitials(string $fullName): string
    {
        $parts = $this->normalizePatientNameParts($fullName);

        if (count($parts) === 0) {
            return 'VP';
        }

        if (count($parts) === 1) {
            return strtoupper(substr($parts[0], 0, 1));
        }

        return strtoupper(substr($parts[0], 0, 1) . substr((string) end($parts), 0, 1));
    }

    /**
     * Normalize patient names so testimonials avoid title-only labels like "MD. A."
     *
     * @return string[]
     */
    private function normalizePatientNameParts(string $fullName): array
    {
        $rawParts = preg_split('/\s+/', trim($fullName)) ?: [];
        $parts = array_values(array_filter(array_map(function (string $part): string {
            $cleaned = trim($part);
            $cleaned = preg_replace('/[^A-Za-z0-9\'\-\.]/', '', $cleaned) ?? '';

            if ($cleaned === '') {
                return '';
            }

            return ucwords(strtolower($cleaned), "-'");
        }, $rawParts), static fn (string $part): bool => $part !== ''));

        $prefixes = ['md', 'md.', 'dr', 'dr.', 'mr', 'mr.', 'mrs', 'mrs.', 'ms', 'ms.', 'miss', 'prof', 'prof.'];

        while (count($parts) > 1) {
            $first = strtolower($parts[0]);
            if (!in_array($first, $prefixes, true)) {
                break;
            }

            array_shift($parts);
        }

        return $parts;
    }
    
    /**
     * Get doctor's schedule
     */
    public function getSchedule(int $doctorId): array
    {
        $sql = "SELECT * FROM doctor_schedules 
                WHERE doctor_id = ? AND is_available = 1 
                ORDER BY weekday";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$doctorId]);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Get available dates for doctor
     */
    public function getAvailableDates(int $doctorId, int $days = 30): array
    {
        $schedule = $this->getSchedule($doctorId);
        $availableWeekdays = array_column($schedule, 'weekday');
        
        if (empty($availableWeekdays)) {
            return [];
        }
        
        $dates = [];
        $today = new \DateTime();
        
        for ($i = 0; $i < $days; $i++) {
            $date = clone $today;
            $date->modify("+{$i} days");
            $weekday = (int) $date->format('w');
            
            if (in_array($weekday, $availableWeekdays, true)) {
                // Get schedule for this weekday
                $daySchedule = array_filter($schedule, fn($s) => $s['weekday'] === $weekday);
                
                foreach ($daySchedule as $slot) {
                    // Get booked count
                    $bookedSql = "SELECT COUNT(*) FROM appointments 
                                  WHERE doctor_id = ? AND appointment_date = ? AND status NOT IN ('cancelled', 'no_show')";
                    $bookedStmt = $this->db->prepare($bookedSql);
                    $bookedStmt->execute([$doctorId, $date->format('Y-m-d')]);
                    $booked = (int) $bookedStmt->fetchColumn();
                    
                    $availableSlots = max(0, $slot['max_patients'] - $booked);
                    
                    if ($availableSlots > 0) {
                        $dates[] = [
                            'date' => $date->format('Y-m-d'),
                            'weekday' => $weekday,
                            'schedule_id' => $slot['id'],
                            'start_time' => $slot['start_time'],
                            'end_time' => $slot['end_time'],
                            'slot_duration' => $slot['slot_duration'],
                            'max_patients' => $slot['max_patients'],
                            'available_slots' => $availableSlots
                        ];
                    }
                }
            }
        }
        
        return $dates;
    }
    
    /**
     * Update doctor rating
     */
    public function updateRating(int $doctorId): bool
    {
        $sql = "UPDATE {$this->table} 
                SET average_rating = COALESCE((SELECT AVG(rating) FROM doctor_reviews WHERE doctor_id = ? AND is_visible = 1), 0.0),
                    total_reviews = (SELECT COUNT(*) FROM doctor_reviews WHERE doctor_id = ? AND is_visible = 1)
                WHERE id = ?";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$doctorId, $doctorId, $doctorId]);
    }
    
    /**
     * Increment appointment count
     */
    public function incrementAppointmentCount(int $doctorId): bool
    {
        $sql = "UPDATE {$this->table} SET total_appointments = total_appointments + 1 WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$doctorId]);
    }
    
    /**
     * Generate unique slug
     */
    public function generateSlug(string $name): string
    {
        $baseSlug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
        $baseSlug = trim($baseSlug, '-');
        
        $slug = $baseSlug;
        $counter = 1;
        
        while ($this->findBySlug($slug)) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }
        
        return $slug;
    }
    
    /**
     * Get doctor statistics
     */
    public function getStatistics(int $doctorId): array
    {
        // Today's appointments
        $todaySql = "SELECT COUNT(*) FROM appointments 
                     WHERE doctor_id = ? AND appointment_date = CURDATE() AND status NOT IN ('cancelled', 'no_show')";
        $todayStmt = $this->db->prepare($todaySql);
        $todayStmt->execute([$doctorId]);
        
        // Weekly appointments
        $weekSql = "SELECT COUNT(*) FROM appointments 
                    WHERE doctor_id = ? AND appointment_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) 
                    AND status NOT IN ('cancelled', 'no_show')";
        $weekStmt = $this->db->prepare($weekSql);
        $weekStmt->execute([$doctorId]);
        
        // Monthly revenue
        $revenueSql = "SELECT COALESCE(SUM(p.amount), 0) FROM payments p 
                       JOIN appointments a ON p.appointment_id = a.id 
                       WHERE a.doctor_id = ? AND p.status = 'success' 
                       AND p.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        $revenueStmt = $this->db->prepare($revenueSql);
        $revenueStmt->execute([$doctorId]);
        
        return [
            'appointments_today' => (int) $todayStmt->fetchColumn(),
            'appointments_this_week' => (int) $weekStmt->fetchColumn(),
            'revenue_this_month' => (float) $revenueStmt->fetchColumn()
        ];
    }
}
