<?php
function getUserProfilePhotoFilename(int $userId): ?string
{
    if ($userId <= 0) {
        return null;
    }

    $directory = __DIR__ . '/../uploads/profile';
    foreach (['jpg', 'png', 'webp'] as $extension) {
        $fileName = $userId . '.' . $extension;
        if (is_file($directory . DIRECTORY_SEPARATOR . $fileName)) {
            return $fileName;
        }
    }

    return null;
}

function saveUserProfilePhoto(int $userId, array $upload): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'message' => 'Unable to identify the account.'];
    }

    $uploadError = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        $message = $uploadError === UPLOAD_ERR_NO_FILE
            ? 'Choose a photo to upload.'
            : ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE
                ? 'Photo must be 2 MB or smaller.'
                : 'The photo could not be uploaded. Please try again.');
        return ['ok' => false, 'message' => $message];
    }

    $temporaryFile = (string)($upload['tmp_name'] ?? '');
    $fileSize = (int)($upload['size'] ?? 0);
    if ($temporaryFile === '' || !is_uploaded_file($temporaryFile)) {
        return ['ok' => false, 'message' => 'The uploaded photo is invalid.'];
    }
    if ($fileSize <= 0 || $fileSize > 2 * 1024 * 1024) {
        return ['ok' => false, 'message' => 'Photo must be between 1 byte and 2 MB.'];
    }

    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryFile);
    $imageInfo = @getimagesize($temporaryFile);
    if (!is_string($mimeType) || !isset($allowedTypes[$mimeType]) || !$imageInfo || ($imageInfo['mime'] ?? '') !== $mimeType) {
        return ['ok' => false, 'message' => 'Use a valid JPG, PNG, or WebP image.'];
    }

    $width = (int)$imageInfo[0];
    $height = (int)$imageInfo[1];
    if ($width <= 0 || $height <= 0 || $width > 4096 || $height > 4096 || $width * $height > 16000000) {
        return ['ok' => false, 'message' => 'Image dimensions must be 4096 pixels or smaller.'];
    }

    $directory = __DIR__ . '/../uploads/profile';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        return ['ok' => false, 'message' => 'Could not prepare profile photo storage.'];
    }

    $fileName = $userId . '.' . $allowedTypes[$mimeType];
    if (!move_uploaded_file($temporaryFile, $directory . DIRECTORY_SEPARATOR . $fileName)) {
        return ['ok' => false, 'message' => 'Could not save the profile photo.'];
    }

    foreach (['jpg', 'png', 'webp'] as $extension) {
        $oldFile = $directory . DIRECTORY_SEPARATOR . $userId . '.' . $extension;
        if ($extension !== $allowedTypes[$mimeType] && is_file($oldFile)) {
            @unlink($oldFile);
        }
    }

    return ['ok' => true, 'message' => 'Profile photo updated.', 'filename' => $fileName];
}