<?php
/**
 * MediSeba - Appointment Chat Message Model
 *
 * Stores appointment-based consultation messages between patients and doctors.
 */

declare(strict_types=1);

namespace MediSeba\Models;

use MediSeba\Config\Environment;

class AppointmentChatMessage extends Model
{
    protected string $table = 'appointment_chat_messages';
    protected string $primaryKey = 'id';

    protected array $fillable = [
        'appointment_id',
        'sender_user_id',
        'sender_role',
        'message_text'
    ];

    protected array $casts = [
        'id' => 'int',
        'appointment_id' => 'int',
        'sender_user_id' => 'int',
        'created_at' => 'datetime'
    ];

    /**
     * Get chat messages for an appointment.
     */
    public function getConversation(int $appointmentId, ?int $sinceId = null): array
    {
        $where = ['m.appointment_id = ?'];
        $params = [$appointmentId];

        if ($sinceId !== null && $sinceId > 0) {
            $where[] = 'm.id > ?';
            $params[] = $sinceId;
        }

        $sql = "SELECT
                m.id,
                m.appointment_id,
                m.sender_user_id,
                m.sender_role,
                m.message_text,
                m.created_at,
                COALESCE(dp.full_name, pp.full_name, u.email, 'User') AS sender_name,
                COALESCE(dp.profile_photo, pp.profile_photo, '') AS sender_profile_photo
                FROM {$this->table} m
                LEFT JOIN users u ON m.sender_user_id = u.id
                LEFT JOIN doctor_profiles dp ON dp.user_id = m.sender_user_id AND m.sender_role = 'doctor'
                LEFT JOIN patient_profiles pp ON pp.user_id = m.sender_user_id AND m.sender_role = 'patient'
                WHERE " . implode(' AND ', $where) . "
                ORDER BY m.id ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return array_map([$this, 'normalizeMessage'], $stmt->fetchAll());
    }

    /**
     * Create a new chat message and return the hydrated row.
     */
    public function createMessage(int $appointmentId, int $senderUserId, string $senderRole, string $messageText): array
    {
        $messageId = $this->create([
            'appointment_id' => $appointmentId,
            'sender_user_id' => $senderUserId,
            'sender_role' => $senderRole,
            'message_text' => $messageText
        ]);

        $message = $this->findMessage($messageId);

        if (!$message) {
            throw new \RuntimeException('Failed to load the newly created chat message.');
        }

        return $message;
    }

    /**
     * Count the number of messages stored for an appointment.
     */
    public function countByAppointment(int $appointmentId): int
    {
        return $this->count('appointment_id = ?', [$appointmentId]);
    }

    /**
     * Get the last message ID for an appointment.
     */
    public function getLastMessageId(int $appointmentId): int
    {
        $stmt = $this->db->prepare("SELECT COALESCE(MAX(id), 0) FROM {$this->table} WHERE appointment_id = ?");
        $stmt->execute([$appointmentId]);

        return (int) $stmt->fetchColumn();
    }

    private function findMessage(int $messageId): ?array
    {
        $sql = "SELECT
                m.id,
                m.appointment_id,
                m.sender_user_id,
                m.sender_role,
                m.message_text,
                m.created_at,
                COALESCE(dp.full_name, pp.full_name, u.email, 'User') AS sender_name,
                COALESCE(dp.profile_photo, pp.profile_photo, '') AS sender_profile_photo
                FROM {$this->table} m
                LEFT JOIN users u ON m.sender_user_id = u.id
                LEFT JOIN doctor_profiles dp ON dp.user_id = m.sender_user_id AND m.sender_role = 'doctor'
                LEFT JOIN patient_profiles pp ON pp.user_id = m.sender_user_id AND m.sender_role = 'patient'
                WHERE m.id = ?
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$messageId]);
        $message = $stmt->fetch();

        return $message ? $this->normalizeMessage($message) : null;
    }

    private function normalizeMessage(array $message): array
    {
        $message['id'] = (int) ($message['id'] ?? 0);
        $message['appointment_id'] = (int) ($message['appointment_id'] ?? 0);
        $message['sender_user_id'] = (int) ($message['sender_user_id'] ?? 0);
        $message['sender_role'] = (string) ($message['sender_role'] ?? '');
        $message['message_text'] = (string) ($message['message_text'] ?? '');
        $message['sender_name'] = trim((string) ($message['sender_name'] ?? 'User')) ?: 'User';
        $message['sender_profile_photo'] = (string) ($message['sender_profile_photo'] ?? '');
        $message['created_at'] = $this->normalizeCreatedAt($message['created_at'] ?? null);

        return $message;
    }

    private function normalizeCreatedAt(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            $timezone = new \DateTimeZone((string) Environment::get('APP_TIMEZONE', 'Asia/Dhaka'));
            $date = $value instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($value)->setTimezone($timezone)
                : new \DateTimeImmutable((string) $value, $timezone);

            return $date->format(\DateTimeInterface::ATOM);
        } catch (\Throwable $e) {
            return (string) $value;
        }
    }
}
