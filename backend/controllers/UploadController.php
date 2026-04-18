<?php
/**
 * MediSeba - Upload Controller
 *
 * Handles authenticated profile photo uploads for patients and doctors.
 */

declare(strict_types=1);

namespace MediSeba\Controllers;

use MediSeba\Config\Environment;
use MediSeba\Models\DoctorProfile;
use MediSeba\Models\PatientProfile;
use MediSeba\Utils\Response;

class UploadController
{
    private string $projectRoot;
    private string $uploadRoot;

    public function __construct()
    {
        $this->projectRoot = dirname(__DIR__, 2);
        $this->uploadRoot = $this->resolveUploadRoot();
    }

    /**
     * Upload or replace the current user's profile photo.
     * POST /api/uploads/profile-photo
     */
    public function profilePhoto(array $user): void
    {
        if (!isset($_FILES['photo'])) {
            Response::validationError(['photo' => 'Please choose an image to upload.']);
        }

        $file = $_FILES['photo'];
        $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($uploadError !== UPLOAD_ERR_OK) {
            Response::validationError(['photo' => $this->mapUploadError($uploadError)]);
        }

        $role = $user['role'] ?? '';
        if (!in_array($role, ['patient', 'doctor'], true)) {
            Response::forbidden('Only patients and doctors can upload profile photos.');
        }

        $size = (int) ($file['size'] ?? 0);
        $maxSize = (int) Environment::get('MAX_UPLOAD_SIZE', '5242880');
        if ($size <= 0) {
            Response::validationError(['photo' => 'The selected image is empty.']);
        }

        if ($size > $maxSize) {
            Response::validationError([
                'photo' => sprintf('Profile photo must be %s or smaller.', $this->formatBytes($maxSize))
            ]);
        }

        $extension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $allowedExtensions = array_values(array_filter(array_map(
            static fn (string $value): string => strtolower(trim($value)),
            explode(',', (string) Environment::get('ALLOWED_IMAGE_TYPES', 'jpg,jpeg,png'))
        )));

        if (!$extension || !in_array($extension, $allowedExtensions, true)) {
            Response::validationError([
                'photo' => 'Only JPG and PNG profile photos are supported.'
            ]);
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        $mimeType = $this->detectMimeType($tmpPath);
        $allowedMimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png'
        ];
        $expectedMimeType = $allowedMimeTypes[$extension] ?? null;

        if (!$mimeType || ($expectedMimeType && $mimeType !== $expectedMimeType)) {
            Response::validationError(['photo' => 'The uploaded file is not a valid image.']);
        }

        if (@getimagesize($tmpPath) === false) {
            Response::validationError(['photo' => 'The uploaded file is not a readable image.']);
        }

        [$profileModel, $profile, $directoryName] = $this->resolveProfileTarget($role, (int) $user['user_id']);

        if (!$profile) {
            Response::error('Please complete your profile before uploading a photo.', [], Response::HTTP_UNPROCESSABLE);
        }

        $relativeDirectory = sprintf('uploads/profile-photos/%s', $directoryName);
        $absoluteDirectory = $this->uploadRoot . '/profile-photos/' . $directoryName;

        if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0755, true) && !is_dir($absoluteDirectory)) {
            Response::serverError('Unable to prepare the upload directory.');
        }

        $fileName = sprintf(
            '%s-%d-%s.%s',
            $directoryName,
            (int) $user['user_id'],
            bin2hex(random_bytes(8)),
            $extension
        );

        $destination = $absoluteDirectory . '/' . $fileName;

        if (!move_uploaded_file($tmpPath, $destination)) {
            Response::serverError('Failed to save the uploaded photo.');
        }

        $relativePath = $relativeDirectory . '/' . $fileName;
        $previousPhoto = $profile['profile_photo'] ?? null;

        if (!$profileModel->update((int) $profile['id'], ['profile_photo' => $relativePath])) {
            @unlink($destination);
            Response::error('Failed to update the profile photo.');
        }

        $this->removePreviousPhoto($previousPhoto, $relativePath);

        $updatedProfile = $profileModel->find((int) $profile['id']);

        Response::success('Profile photo updated successfully', [
            'profile_photo' => $relativePath,
            'profile' => $updatedProfile
        ]);
    }

    private function resolveProfileTarget(string $role, int $userId): array
    {
        if ($role === 'doctor') {
            $model = new DoctorProfile();
            return [$model, $model->findByUserId($userId), 'doctors'];
        }

        $model = new PatientProfile();
        return [$model, $model->findByUserId($userId), 'patients'];
    }

    private function detectMimeType(string $filePath): ?string
    {
        if ($filePath === '' || !is_file($filePath)) {
            return null;
        }

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mimeType = finfo_file($finfo, $filePath) ?: null;
                finfo_close($finfo);
                return $mimeType ?: null;
            }
        }

        return mime_content_type($filePath) ?: null;
    }

    private function mapUploadError(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The selected image is too large.',
            UPLOAD_ERR_PARTIAL => 'The image upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE => 'Please choose an image to upload.',
            default => 'Unable to upload the selected image right now.'
        };
    }

    private function formatBytes(int $bytes): string
    {
        $megabytes = $bytes / 1024 / 1024;
        return rtrim(rtrim(number_format($megabytes, 1), '0'), '.') . ' MB';
    }

    private function removePreviousPhoto(?string $currentPhoto, string $replacementPhoto): void
    {
        if (!$currentPhoto || $currentPhoto === $replacementPhoto) {
            return;
        }

        $normalized = ltrim(str_replace('\\', '/', $currentPhoto), '/');

        if (!str_starts_with($normalized, 'uploads/profile-photos/')) {
            return;
        }

        foreach ($this->candidatePhotoPaths($normalized) as $absolutePath) {
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }
        }
    }

    private function resolveUploadRoot(): string
    {
        $rootUploads = $this->projectRoot . '/uploads';
        $frontendUploads = $this->projectRoot . '/frontend/uploads';

        $isRootDeployment = is_file($this->projectRoot . '/index.html')
            && is_dir($this->projectRoot . '/js');

        if ($isRootDeployment) {
            return $rootUploads;
        }

        if (is_dir($frontendUploads) || is_dir($this->projectRoot . '/frontend')) {
            return $frontendUploads;
        }

        return $rootUploads;
    }

    private function candidatePhotoPaths(string $normalizedPath): array
    {
        return array_values(array_unique([
            $this->projectRoot . '/' . $normalizedPath,
            $this->projectRoot . '/frontend/' . $normalizedPath
        ]));
    }
}
