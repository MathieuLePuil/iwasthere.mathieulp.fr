<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\KernelInterface;

class AvatarService
{
    private string $uploadDir;

    public function __construct(KernelInterface $kernel)
    {
        $this->uploadDir = $kernel->getProjectDir() . '/public/uploads/avatars';
    }

    /**
     * Saves an uploaded file as the user's avatar, returns the public path or null on failure.
     */
    public function saveUploadedFile(UploadedFile $file, string $userId): ?string
    {
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (!in_array($file->getMimeType(), $allowedMimes, true)) {
            return null;
        }

        $ext = match ($file->getMimeType()) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            default      => null,
        };

        if ($ext === null) {
            return null;
        }

        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }

        $filename = $userId . '.' . $ext;
        $filepath = $this->uploadDir . '/' . $filename;

        // Remove old avatar files with different extension
        foreach (['jpg', 'png', 'webp', 'gif'] as $oldExt) {
            $oldFile = $this->uploadDir . '/' . $userId . '.' . $oldExt;
            if ($oldExt !== $ext && file_exists($oldFile)) {
                @unlink($oldFile);
            }
        }

        try {
            $file->move($this->uploadDir, $filename);
        } catch (\Throwable) {
            return null;
        }

        return '/uploads/avatars/' . $filename . '?v=' . time();
    }

    /**
     * Downloads an image from $url, stores it locally, returns the public path.
     * Returns null on failure.
     */
    public function downloadFromUrl(string $url, string $userId): ?string
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'user_agent' => 'IWasThere/1.0',
            ],
            'ssl' => [
                'verify_peer' => true,
            ],
        ]);

        $data = @file_get_contents($url, false, $context);
        if ($data === false || strlen($data) < 100) {
            return null;
        }

        // Detect image type from content
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($data);
        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            default      => null,
        };

        if ($ext === null) {
            return null;
        }

        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }

        $filename = $userId . '.' . $ext;
        $filepath = $this->uploadDir . '/' . $filename;

        if (file_put_contents($filepath, $data) === false) {
            return null;
        }

        return '/uploads/avatars/' . $filename;
    }
}
