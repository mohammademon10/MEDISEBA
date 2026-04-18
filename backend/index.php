<?php
/**
 * MediSeba - API Entry Point
 * 
 * Main router for all API endpoints
 * Handles request routing, middleware, and error handling
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/environment.php';
use MediSeba\Config\Environment;

// Initialize environment early to read CORS and Security options
try {
    Environment::load(__DIR__ . '/../.env');
} catch (Exception $e) {
    // Continue with defaults if .env not found
}

if (Environment::isDebug()) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
    ini_set('display_errors', '0');
}

// Support PHP built-in server for local frontend + backend development.
if (php_sapi_name() === 'cli-server') {
    $requestedFile = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $documentRoot = realpath(__DIR__ . '/..');
    $staticFile = realpath($documentRoot . $requestedFile);

    if ($staticFile !== false && is_file($staticFile) && str_starts_with($staticFile, $documentRoot)) {
        return false;
    }
}

// Secure CORS
$allowedOrigins = array_values(array_filter(array_map(
    static fn (string $value): string => trim($value),
    explode(',', (string) Environment::get('CORS_ALLOWED_ORIGINS', '*'))
)));
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowOrigin = '*';
$allowCredentials = 'false';

if (!empty($allowedOrigins) && !in_array('*', $allowedOrigins, true)) {
    $allowOrigin = in_array($origin, $allowedOrigins, true) ? $origin : $allowedOrigins[0];
    $allowCredentials = 'true';
}

header("Access-Control-Allow-Origin: {$allowOrigin}");
header('Access-Control-Allow-Methods: ' . Environment::get('CORS_ALLOWED_METHODS', 'GET,POST,PUT,PATCH,DELETE,OPTIONS'));
header('Access-Control-Allow-Headers: ' . Environment::get('CORS_ALLOWED_HEADERS', 'Content-Type,Authorization,X-Authorization,X-Auth-Token,X-Requested-With,X-CSRF-Token'));
header("Access-Control-Allow-Credentials: {$allowCredentials}");

// Security Headers
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; connect-src 'self' https://api.emailjs.com; img-src 'self' data:;");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Apache sometimes drops the Authorization header
if (!isset($_SERVER['HTTP_AUTHORIZATION'])) {
    if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['HTTP_X_AUTHORIZATION'])) {
        $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['HTTP_X_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_X_AUTHORIZATION'])) {
        $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_X_AUTHORIZATION'];
    } elseif (isset($_SERVER['HTTP_X_AUTH_TOKEN'])) {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $_SERVER['HTTP_X_AUTH_TOKEN'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_X_AUTH_TOKEN'])) {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $_SERVER['REDIRECT_HTTP_X_AUTH_TOKEN'];
    } elseif (function_exists('apache_request_headers')) {
        $requestHeaders = apache_request_headers();
        $requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));
        if (isset($requestHeaders['Authorization'])) {
            $_SERVER['HTTP_AUTHORIZATION'] = $requestHeaders['Authorization'];
        } elseif (isset($requestHeaders['X-Authorization'])) {
            $_SERVER['HTTP_AUTHORIZATION'] = $requestHeaders['X-Authorization'];
        } elseif (isset($requestHeaders['X-Auth-Token'])) {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $requestHeaders['X-Auth-Token'];
        }
    }
}

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'MediSeba\\';
    $baseDir = __DIR__ . '/';
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relativeClass = substr($class, $len);
    $parts = explode('\\', $relativeClass);

    if (!empty($parts[0])) {
        $parts[0] = strtolower($parts[0]);
    }

    $file = $baseDir . implode('/', $parts) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});

// Set timezone from environment or default
$timezone = \MediSeba\Config\Environment::get('APP_TIMEZONE', 'Asia/Dhaka');
date_default_timezone_set($timezone);

// Load configuration
require_once __DIR__ . '/config/database.php';

use MediSeba\Utils\Response;
use MediSeba\Utils\Security;
use MediSeba\Middleware\AuthMiddleware;
use MediSeba\Controllers\AuthController;
use MediSeba\Controllers\ConfigController;
use MediSeba\Controllers\DoctorController;
use MediSeba\Controllers\AppointmentController;
use MediSeba\Controllers\PrescriptionController;
use MediSeba\Controllers\PaymentController;
use MediSeba\Controllers\UploadController;
use MediSeba\Controllers\Admin\AdminDashboardController;
use MediSeba\Controllers\Admin\AdminUserController;
use MediSeba\Controllers\Admin\AdminDoctorController;
use MediSeba\Controllers\Admin\AdminPatientController;
use MediSeba\Controllers\Admin\AdminAppointmentController;
use MediSeba\Controllers\Admin\AdminPaymentController;
use MediSeba\Controllers\Admin\AdminPrescriptionController;
use MediSeba\Controllers\Admin\AdminSettingsController;
use MediSeba\Controllers\GeminiController;

// Initialize secure session
Security::initSecureSession();

// Get request method and path
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$scriptDir = str_replace('\\', '/', dirname($scriptName));
$scriptDir = $scriptDir === '/' || $scriptDir === '.' ? '' : rtrim($scriptDir, '/');

if ($scriptName !== '' && str_starts_with($path, $scriptName)) {
    $path = substr($path, strlen($scriptName));
} elseif ($scriptDir !== '' && str_starts_with($path, $scriptDir)) {
    $path = substr($path, strlen($scriptDir));
}

$path = trim($path, '/');

// Get request body
$requestBody = [];
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (strpos($contentType, 'application/json') !== false) {
    $input = file_get_contents('php://input');
    $requestBody = json_decode($input, true) ?? [];
} else {
    $requestBody = $_POST;
}

// Merge query parameters for GET requests
if ($method === 'GET') {
    $requestBody = array_merge($requestBody, $_GET);
}

// API Router
$routes = [
    // Authentication Routes
    'POST api/auth/login' => [AuthController::class, 'login', 'public'],
    'POST api/auth/register' => [AuthController::class, 'register', 'public'],
    'POST api/auth/register-admin' => [AuthController::class, 'registerAdmin', 'public'],
    'GET api/auth/me' => [AuthController::class, 'me', 'auth'],
    'POST api/auth/logout' => [AuthController::class, 'logout', 'auth'],
    'POST api/auth/refresh' => [AuthController::class, 'refresh', 'auth'],
    'POST api/auth/complete-profile' => [AuthController::class, 'completeProfile', 'auth'],
    'PUT api/auth/profile' => [AuthController::class, 'updateProfile', 'patient'],
    'GET api/config/public' => [ConfigController::class, 'publicConfig', 'public'],
    'POST api/uploads/profile-photo' => [UploadController::class, 'profilePhoto', 'auth'],


    // Gemini AI Routes
    'POST api/gemini/explain' => [GeminiController::class, 'explainTerm', 'patient'],
    'POST api/gemini/summarize-symptoms' => [GeminiController::class, 'parseSymptoms', 'patient'],
    'POST api/gemini/clinical-summary' => [GeminiController::class, 'clinicalSummary', 'doctor'],

    // Doctor Routes
    'GET api/doctors' => [DoctorController::class, 'index', 'public'],
    'GET api/doctors/featured' => [DoctorController::class, 'featured', 'public'],
    'GET api/doctors/testimonials' => [DoctorController::class, 'testimonials', 'public'],
    'GET api/doctors/public-stats' => [DoctorController::class, 'publicStats', 'public'],
    'GET api/doctors/specialties' => [DoctorController::class, 'specialties', 'public'],
    'GET api/doctors/statistics' => [DoctorController::class, 'statistics', 'doctor'],
    'GET api/doctors/profile' => [DoctorController::class, 'showProfile', 'doctor'],
    'PUT api/doctors/profile' => [DoctorController::class, 'updateProfile', 'doctor'],
    'PUT api/doctors/schedule' => [DoctorController::class, 'updateSchedule', 'doctor'],
    'GET api/doctors/{id}' => [DoctorController::class, 'show', 'public'],
    'GET api/doctors/{id}/schedule' => [DoctorController::class, 'schedule', 'public'],
    'GET api/doctors/{id}/available-dates' => [DoctorController::class, 'availableDates', 'public'],
    
    // Appointment Routes
    'POST api/appointments' => [AppointmentController::class, 'store', 'patient'],
    'GET api/appointments/my-appointments' => [AppointmentController::class, 'myAppointments', 'patient'],
    'GET api/appointments/doctor-appointments' => [AppointmentController::class, 'doctorAppointments', 'doctor'],
    'GET api/appointments/today' => [AppointmentController::class, 'todayAppointments', 'doctor'],
    'GET api/appointments/upcoming' => [AppointmentController::class, 'upcoming', 'patient'],
    'GET api/appointments/statistics' => [AppointmentController::class, 'statistics', 'auth'],
    'GET api/appointments/{id}' => [AppointmentController::class, 'show', 'auth'],
    'POST api/appointments/{id}/review' => [AppointmentController::class, 'saveReview', 'patient'],
    'GET api/appointments/{id}/video-room' => [AppointmentController::class, 'videoRoom', 'auth'],
    'PATCH api/appointments/{id}/status' => [AppointmentController::class, 'updateStatus', 'auth'],
    'POST api/appointments/{id}/cancel' => [AppointmentController::class, 'cancel', 'auth'],
    
    // Prescription Routes
    'POST api/prescriptions' => [PrescriptionController::class, 'store', 'doctor'],
    'GET api/prescriptions/my-prescriptions' => [PrescriptionController::class, 'myPrescriptions', 'patient'],
    'GET api/prescriptions/doctor-prescriptions' => [PrescriptionController::class, 'doctorPrescriptions', 'doctor'],
    'GET api/prescriptions/search' => [PrescriptionController::class, 'search', 'patient'],
    'GET api/prescriptions/follow-ups' => [PrescriptionController::class, 'followUps', 'auth'],
    'GET api/prescriptions/{id}/pdf' => [PrescriptionController::class, 'downloadPdf', 'auth'],
    'GET api/prescriptions/{id}' => [PrescriptionController::class, 'show', 'auth'],
    'PUT api/prescriptions/{id}' => [PrescriptionController::class, 'update', 'doctor'],
    'DELETE api/prescriptions/{id}' => [PrescriptionController::class, 'delete', 'doctor'],
    
    // Payment Routes
    'GET api/payments/my-payments' => [PaymentController::class, 'myPayments', 'patient'],
    'GET api/payments/doctor-payments' => [PaymentController::class, 'doctorPayments', 'doctor'],
    'GET api/payments/statistics' => [PaymentController::class, 'statistics', 'auth'],
    'GET api/payments/daily-revenue' => [PaymentController::class, 'dailyRevenue', 'auth'],
    'POST api/payments/initiate' => [PaymentController::class, 'initiate', 'patient'],
    'POST api/payments/callback' => [PaymentController::class, 'callback', 'public'],
    'POST api/payments/{id}/refund' => [PaymentController::class, 'refund', 'doctor'],
    'GET api/payments/{id}/receipt' => [PaymentController::class, 'downloadReceipt', 'auth'],
    'GET api/payments/{id}' => [PaymentController::class, 'show', 'auth'],

    // Admin Routes
    'GET api/admin/dashboard/stats' => [AdminDashboardController::class, 'stats', 'admin'],
    'GET api/admin/dashboard/activity' => [AdminDashboardController::class, 'recentActivity', 'admin'],
    'GET api/admin/users' => [AdminUserController::class, 'index', 'admin'],
    'PATCH api/admin/users/{id}/status' => [AdminUserController::class, 'updateStatus', 'admin'],
    'DELETE api/admin/users/{id}' => [AdminUserController::class, 'delete', 'admin'],
    'GET api/admin/doctors' => [AdminDoctorController::class, 'index', 'admin'],
    'GET api/admin/doctors/{id}' => [AdminDoctorController::class, 'show', 'admin'],
    'PATCH api/admin/doctors/{id}/verify' => [AdminDoctorController::class, 'verify', 'admin'],
    'PUT api/admin/doctors/{id}' => [AdminDoctorController::class, 'update', 'admin'],
    'GET api/admin/patients' => [AdminPatientController::class, 'index', 'admin'],
    'PATCH api/admin/patients/{id}/status' => [AdminPatientController::class, 'updateStatus', 'admin'],
    'GET api/admin/appointments' => [AdminAppointmentController::class, 'index', 'admin'],
    'POST api/admin/appointments/{id}/cancel' => [AdminAppointmentController::class, 'cancel', 'admin'],
    'GET api/admin/payments' => [AdminPaymentController::class, 'index', 'admin'],
    'GET api/admin/payments/{id}/receipt' => [AdminPaymentController::class, 'receipt', 'admin'],
    'GET api/admin/prescriptions' => [AdminPrescriptionController::class, 'index', 'admin'],
    'GET api/admin/prescriptions/{id}' => [AdminPrescriptionController::class, 'show', 'admin'],
    'GET api/admin/settings' => [AdminSettingsController::class, 'index', 'admin'],
    'PUT api/admin/settings' => [AdminSettingsController::class, 'update', 'admin'],
];

// Match route
$matchedRoute = null;
$routeParams = [];

foreach ($routes as $route => $handler) {
    list($routeMethod, $routePath) = explode(' ', $route, 2);
    
    if ($routeMethod !== $method) {
        continue;
    }
    
    // Convert route pattern to regex
    $pattern = preg_replace('/\{([^}]+)\}/', '([^/]+)', $routePath);
    $pattern = '#^' . $pattern . '$#';
    
    if (preg_match($pattern, $path, $matches)) {
        $matchedRoute = $handler;
        array_shift($matches); // Remove full match
        $routeParams = $matches;
        break;
    }
}

// Handle route
if ($matchedRoute) {
    list($controllerClass, $methodName, $authLevel) = $matchedRoute;
    
    try {
        // Handle authentication
        $user = null;
        
        switch ($authLevel) {
            case 'public':
                $user = AuthMiddleware::optionalAuth();
                break;
            case 'auth':
                $user = AuthMiddleware::requireAuth();
                break;
            case 'patient':
                $user = AuthMiddleware::requirePatient();
                break;
            case 'doctor':
                $user = AuthMiddleware::requireDoctor();
                break;
            case 'admin':
                $user = AuthMiddleware::requireAdmin();
                break;
        }
        
        // Instantiate controller and call method
        $controller = new $controllerClass();
        
        // Use reflection to match parameters by type
        $refMethod = new \ReflectionMethod($controllerClass, $methodName);
        $params = [];
        $routeParamIndex = 0;
        
        foreach ($refMethod->getParameters() as $refParam) {
            $paramName = $refParam->getName();
            $paramType = $refParam->getType();
            $typeName = null;
            if ($paramType instanceof \ReflectionNamedType) {
                $typeName = $paramType->getName();
            }
            
            // Match 'user' parameter — the authenticated user array
            if ($paramName === 'user' && $typeName === 'array') {
                $params[] = $user ?? [];
            }
            // Match route path parameters (int or string from URL like {id})
            elseif (($typeName === 'int' || $typeName === 'string') && $routeParamIndex < count($routeParams)) {
                $val = $routeParams[$routeParamIndex++];
                $params[] = $typeName === 'int' ? (int) $val : $val;
            }
            // Match 'request' parameter — the request body / query params
            elseif ($paramName === 'request' && $typeName === 'array') {
                $params[] = $requestBody;
            }
            // Fallback: if optional, use default; otherwise pass empty array or null
            elseif ($refParam->isDefaultValueAvailable()) {
                $params[] = $refParam->getDefaultValue();
            } else {
                $params[] = $typeName === 'array' ? [] : null;
            }
        }
        
        call_user_func_array([$controller, $methodName], $params);
        
    } catch (Exception $e) {
        if (Environment::isDebug()) {
            Response::serverError($e->getMessage());
        } else {
            Response::serverError('An error occurred while processing your request');
        }
    }
} else {
    // Route not found
    Response::notFound('Endpoint');
}
