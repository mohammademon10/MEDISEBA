<?php
/**
 * MediSeba - Authentication Controller
 * 
 * Handles email and password authentication
 */

declare(strict_types=1);

namespace MediSeba\Controllers;

use MediSeba\Utils\Response;
use MediSeba\Utils\Validator;
use MediSeba\Utils\Security;
use MediSeba\Utils\RateLimiter;
use MediSeba\Models\User;
use MediSeba\Models\PatientProfile;
use MediSeba\Models\DoctorProfile;

class AuthController
{
    private User $userModel;
    
    public function __construct()
    {
        $this->userModel = new User();
    }
    
    /**
     * Register a new user
     * POST /api/auth/register
     */
    public function register(array $request): void
    {
        // Validate input
        $validator = Validator::quick($request, [
            'full_name' => 'required|min:3|max:100',
            'email' => 'required|email',
            'password' => 'required|min:6',
            'role' => 'in:patient,doctor'
        ]);
        
        if (!$validator['valid']) {
            Response::validationError($validator['errors']);
        }
        
        $email = filter_var($request['email'], FILTER_SANITIZE_EMAIL);
        $role = $request['role'] ?? 'patient';
        
        // Check if user already exists
        $existingUser = $this->userModel->findByEmail($email);
        if ($existingUser) {
            Response::conflict('Email is already registered. Please login instead.');
        }

        // Hash the password
        $passwordHash = password_hash($request['password'], PASSWORD_BCRYPT);
        
        if ($role === 'patient') {
            // Enterprise Optimization: Delegate atomic registration payload natively to SQL procedure
            $db = \MediSeba\Config\Database::getConnection();
            $stmt = $db->prepare("CALL sp_register_patient(?, ?, ?, NULL, NULL, @out_id)");
            $stmt->execute([$email, $passwordHash, $request['full_name']]);
            
            // Extract the generated primary key
            $userId = (int) $db->query("SELECT @out_id")->fetchColumn();
            $this->userModel->update($userId, ['email_verified_at' => date('Y-m-d H:i:s')]);
            $user = $this->userModel->find($userId);
        } elseif ($role === 'doctor') {
            // Create user
            $userId = $this->userModel->create([
                'email' => $email,
                'password_hash' => $passwordHash,
                'role' => $role,
                'status' => 'active',
                'email_verified_at' => date('Y-m-d H:i:s')
            ]);
            $user = $this->userModel->find($userId);

            // Cascade profile construction
            $profileModel = new DoctorProfile();
            $slug = $profileModel->generateSlug($request['full_name']);
            $profileModel->create([
                'user_id' => $userId,
                'full_name' => $request['full_name'],
                'slug' => $slug,
                'specialty' => $request['specialty'] ?? 'General Physician',
                'qualification' => $request['qualification'] ?? 'MBBS',
                'is_verified' => 0
            ]);
        }

        // Generate JWT token
        $token = Security::generateJWT([
            'user_id' => $user['id'],
            'email' => $user['email'],
            'role' => $user['role']
        ]);
        
        Response::success('Registration successful', [
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'role' => $user['role'],
                'status' => $user['status']
            ]
        ]);
    }

    /**
     * Authenticate user with password
     * POST /api/auth/login
     */
    public function login(array $request): void
    {
        // Validate input
        $validator = Validator::quick($request, [
            'email' => 'required|email',
            'password' => 'required',
            'role' => 'in:patient,doctor,admin'
        ]);
        
        if (!$validator['valid']) {
            Response::validationError($validator['errors']);
        }
        
        $email = filter_var($request['email'], FILTER_SANITIZE_EMAIL);
        $role = $request['role'] ?? 'patient';
        
        // Check login rate limit
        $rateLimitCheck = RateLimiter::checkLoginLimit($email);
        if (!$rateLimitCheck['allowed']) {
            Response::tooManyRequests($rateLimitCheck['retry_after']);
        }

        $user = $this->userModel->findByEmailForAuth($email);
        
        if (!$user || !password_verify($request['password'], $user['password_hash'])) {
            RateLimiter::recordLoginAttempt($email);
            Response::error('Invalid email or password', [], 401);
        }

        $this->assertRoleMatchesExistingUser($user, $role);
        
        // Update last login
        $this->userModel->updateLastLogin($user['id']);
        
        // Generate JWT token
        $token = Security::generateJWT([
            'user_id' => $user['id'],
            'email' => $user['email'],
            'role' => $user['role']
        ]);
        
        // Get profile based on role
        $profile = null;
        if ($user['role'] === 'patient') {
            $profileModel = new PatientProfile();
            $profile = $profileModel->findByUserId($user['id']);
        } elseif ($user['role'] === 'doctor') {
            $profileModel = new DoctorProfile();
            $profile = $profileModel->findByUserId($user['id']);
        }
        
        // Clear login attempts on successful login
        RateLimiter::clearLoginAttempts($email);
        
        Response::success('Authentication successful', [
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'role' => $user['role'],
                'status' => $user['status']
            ],
            'profile' => $profile
        ]);
    }
    
    /**
     * Get current user profile
     * GET /api/auth/me
     */
    public function me(array $user): void
    {
        $fullUser = $this->userModel->find($user['user_id']);
        
        if (!$fullUser) {
            Response::notFound('User');
        }
        
        // Get profile based on role
        $profile = null;
        if ($fullUser['role'] === 'patient') {
            $profileModel = new PatientProfile();
            $profile = $profileModel->findByUserId($fullUser['id']);
        } elseif ($fullUser['role'] === 'doctor') {
            $profileModel = new DoctorProfile();
            $profile = $profileModel->findByUserId($fullUser['id']);
        }
        
        Response::success('User profile retrieved', [
            'user' => [
                'id' => $fullUser['id'],
                'email' => $fullUser['email'],
                'role' => $fullUser['role'],
                'status' => $fullUser['status'],
                'last_login_at' => $fullUser['last_login_at']
            ],
            'profile' => $profile
        ]);
    }
    
    /**
     * Logout user
     * POST /api/auth/logout
     */
    public function logout(array $user): void
    {
        Response::success('Logged out successfully');
    }

    /**
     * Refresh JWT token
     * POST /api/auth/refresh
     */
    public function refresh(array $user): void
    {
        $fullUser = $this->userModel->find($user['user_id']);

        if (!$fullUser || $fullUser['status'] !== 'active') {
            Response::error('Account is no longer active', [], 401);
        }

        $token = Security::generateJWT([
            'user_id' => $fullUser['id'],
            'email'   => $fullUser['email'],
            'role'    => $fullUser['role']
        ]);

        Response::success('Token refreshed', ['token' => $token]);
    }
    
    /**
     * Complete patient profile
     * PUT /api/auth/profile
     */
    public function updateProfile(array $request, array $user): void
    {
        if ($user['role'] !== 'patient') {
            Response::forbidden('Only patients can update their profiles via this endpoint');
        }
        
        $validator = Validator::quick($request, [
            'full_name' => 'required|min:3|max:100',
            'date_of_birth' => 'date|past',
            'gender' => 'in:male,female,other,prefer_not_to_say',
            'blood_group' => 'in:A+,A-,B+,B-,AB+,AB-,O+,O-',
            'address' => 'max:500'
        ]);
        
        if (!$validator['valid']) {
            Response::validationError($validator['errors']);
        }
        
        $profileModel = new PatientProfile();
        $result = $profileModel->createOrUpdate($user['user_id'], [
            'full_name' => $request['full_name'],
            'date_of_birth' => $request['date_of_birth'] ?? null,
            'gender' => $request['gender'] ?? null,
            'blood_group' => $request['blood_group'] ?? null,
            'address' => $request['address'] ?? null,
            'emergency_contact_name' => $request['emergency_contact_name'] ?? null,
            'emergency_contact_phone' => $request['emergency_contact_phone'] ?? null
        ]);
        
        if ($result['success']) {
            Response::success('Profile updated successfully', $result);
        } else {
            Response::error('Failed to update profile');
        }
    }

    /**
     * Securely register an Admin User
     * POST /api/auth/register-admin
     */
    public function registerAdmin(array $request): void
    {
        // Enforce strong validation
        $validator = Validator::quick($request, [
            'email'    => 'required|email',
            // Strong password enforcement: min 8, letters, numbers, symbols
            'password' => 'required|min:8|regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/',
            'admin_secret' => 'required' 
        ]);

        if (!$validator['valid']) {
            Response::validationError($validator['errors']);
        }

        // Hardcoded backend protection mechanism to block unauthorized escalation
        $adminSecret = \MediSeba\Config\Environment::get('ADMIN_SETUP_SECRET', 'SuperSecretAdmin123!');
        if ($request['admin_secret'] !== $adminSecret) {
            Response::forbidden('Invalid administrative capability signature.');
        }

        $email = filter_var($request['email'], FILTER_SANITIZE_EMAIL);
        
        $existingUser = $this->userModel->findByEmail($email);
        if ($existingUser) {
            Response::conflict('Email is already utilized. Constraint check failed.');
        }

        $passwordHash = password_hash($request['password'], PASSWORD_BCRYPT);
        
        $this->userModel->create([
            'email' => $email,
            'password_hash' => $passwordHash,
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => date('Y-m-d H:i:s')
        ]);

        Response::success('Administrative entity securely provisioned.', []);
    }

    private function assertRoleMatchesExistingUser(?array $user, string $requestedRole): void
    {
        if (!$user || $user['role'] === $requestedRole) {
            return;
        }

        $pageMap = [
            'doctor' => 'Doctor Login',
            'admin' => 'Admin Login',
            'patient' => 'Patient Login',
        ];
        $targetPage = $pageMap[$user['role']] ?? 'Login';

        Response::conflict(
            sprintf(
                'This email is registered as a %s. Please use the %s page.',
                $user['role'],
                $targetPage
            )
        );
    }

    /**
     * Complete or update profile after registration
     * POST /api/auth/complete-profile
     */
    public function completeProfile(array $request, array $user): void
    {
        if ($user['role'] === 'patient') {
            $profileModel = new PatientProfile();
            $result = $profileModel->createOrUpdate($user['user_id'], [
                'full_name'                => $request['full_name'] ?? null,
                'date_of_birth'            => $request['date_of_birth'] ?? null,
                'gender'                   => $request['gender'] ?? null,
                'blood_group'              => $request['blood_group'] ?? null,
                'address'                  => $request['address'] ?? null,
                'emergency_contact_name'   => $request['emergency_contact_name'] ?? null,
                'emergency_contact_phone'  => $request['emergency_contact_phone'] ?? null,
            ]);
        } elseif ($user['role'] === 'doctor') {
            $profileModel = new DoctorProfile();
            $result = $profileModel->createOrUpdate($user['user_id'], [
                'full_name'         => $request['full_name'] ?? null,
                'specialty'         => $request['specialty'] ?? null,
                'qualification'     => $request['qualification'] ?? null,
                'experience_years'  => isset($request['experience_years']) ? (int) $request['experience_years'] : null,
                'consultation_fee'  => isset($request['consultation_fee']) ? (float) $request['consultation_fee'] : null,
                'bio'               => $request['bio'] ?? null,
                'clinic_name'       => $request['clinic_name'] ?? null,
                'clinic_address'    => $request['clinic_address'] ?? null,
                'languages'         => isset($request['languages']) ? (is_array($request['languages']) ? json_encode($request['languages']) : $request['languages']) : null,
                'registration_number' => $request['registration_number'] ?? null,
            ]);
        } else {
            Response::forbidden('Profile completion is only available for patients and doctors');
            return;
        }

        if (!empty($result['success'])) {
            Response::success('Profile completed successfully', $result);
        } else {
            Response::error($result['message'] ?? 'Failed to complete profile');
        }
    }
}
