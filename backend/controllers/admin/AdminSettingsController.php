<?php
/**
 * MediSeba - Admin Settings Controller
 * 
 * System settings management
 */

declare(strict_types=1);

namespace MediSeba\Controllers\Admin;

use MediSeba\Utils\Response;
use MediSeba\Config\Database;

class AdminSettingsController
{
    /**
     * GET /api/admin/settings
     */
    public function index(array $user): void
    {
        $db = Database::getConnection();

        $stmt = $db->query("
            SELECT id, setting_key, setting_value, setting_group, is_encrypted, created_at, updated_at
            FROM system_settings
            ORDER BY setting_group, setting_key
        ");
        $settings = $stmt->fetchAll();

        // Group by setting_group
        $grouped = [];
        foreach ($settings as $setting) {
            $group = $setting['setting_group'] ?? 'general';
            $grouped[$group][] = $setting;
        }

        Response::success('Settings retrieved', [
            'settings' => $settings,
            'grouped' => $grouped
        ]);
    }

    /**
     * PUT /api/admin/settings
     */
    public function update(array $user, array $request): void
    {
        $settings = $request['settings'] ?? [];

        if (empty($settings) || !is_array($settings)) {
            Response::error('No settings provided');
        }

        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            $stmt = $db->prepare("
                UPDATE system_settings 
                SET setting_value = ?, updated_at = NOW() 
                WHERE setting_key = ?
            ");

            $updated = 0;
            foreach ($settings as $key => $value) {
                $stmt->execute([(string) $value, (string) $key]);
                $updated += $stmt->rowCount();
            }

            // Log activity
            $logStmt = $db->prepare("
                INSERT INTO activity_logs (user_id, action, entity_type, ip_address, user_agent)
                VALUES (?, 'settings_updated', 'system_settings', ?, ?)
            ");
            $logStmt->execute([
                $user['user_id'],
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);

            $db->commit();
            Response::success("Updated {$updated} setting(s) successfully");
        } catch (\Exception $e) {
            $db->rollBack();
            Response::serverError('Failed to update settings');
        }
    }
}
