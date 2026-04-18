<?php
/**
 * MediSeba - Public Configuration Controller
 *
 * Exposes safe runtime configuration needed by the frontend.
 */

declare(strict_types=1);

namespace MediSeba\Controllers;

use MediSeba\Config\Environment;
use MediSeba\Utils\Response;

class ConfigController
{
    /**
     * Get public frontend configuration
     * GET /api/config/public
     */
    public function publicConfig(): void
    {
        Response::success('Public configuration retrieved', [
            'app_name' => trim((string) Environment::get('APP_NAME', 'MediSeba')),
            'otp_expiry_minutes' => (int) Environment::get('OTP_EXPIRY_MINUTES', 5),
            'otp_delivery_mode' => trim((string) Environment::get('OTP_DELIVERY_MODE', 'server_emailjs')),
            'email_otp' => [
                'public_key' => trim((string) Environment::get('EMAILJS_PUBLIC_KEY', '')),
                'service_id' => trim((string) Environment::get('EMAILJS_SERVICE_ID', '')),
                'template_id' => trim((string) Environment::get('EMAILJS_TEMPLATE_ID', ''))
            ]
        ]);
    }
}
