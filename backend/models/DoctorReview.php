<?php
/**
 * MediSeba - Doctor Review Model
 *
 * Handles patient reviews for completed appointments.
 */

declare(strict_types=1);

namespace MediSeba\Models;

class DoctorReview extends Model
{
    protected string $table = 'doctor_reviews';
    protected string $primaryKey = 'id';

    protected array $fillable = [
        'doctor_id',
        'patient_id',
        'appointment_id',
        'rating',
        'review_text',
        'is_visible'
    ];

    protected array $casts = [
        'id' => 'int',
        'doctor_id' => 'int',
        'patient_id' => 'int',
        'appointment_id' => 'int',
        'rating' => 'int',
        'is_visible' => 'bool',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    public function findByAppointmentId(int $appointmentId): ?array
    {
        return $this->findBy('appointment_id', $appointmentId);
    }

    public function upsertForAppointment(
        int $doctorId,
        int $patientId,
        int $appointmentId,
        int $rating,
        ?string $reviewText = null
    ): array {
        $existing = $this->findByAppointmentId($appointmentId);
        $normalizedText = trim((string) ($reviewText ?? ''));
        $payload = [
            'doctor_id' => $doctorId,
            'patient_id' => $patientId,
            'appointment_id' => $appointmentId,
            'rating' => $rating,
            'review_text' => $normalizedText !== '' ? $normalizedText : null,
            'is_visible' => 1
        ];

        if ($existing) {
            $success = $this->update((int) $existing['id'], $payload);

            return [
                'success' => $success,
                'id' => (int) $existing['id'],
                'action' => 'updated'
            ];
        }

        $id = $this->create($payload);

        return [
            'success' => true,
            'id' => $id,
            'action' => 'created'
        ];
    }
}
