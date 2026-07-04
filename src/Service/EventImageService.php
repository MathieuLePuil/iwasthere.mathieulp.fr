<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\KernelInterface;

class EventImageService
{
    private string $uploadDir;

    public function __construct(KernelInterface $kernel)
    {
        $this->uploadDir = $kernel->getProjectDir() . '/public/uploads/events';
    }

    /**
     * Saves an uploaded file as a participant's photo for an event,
     * returns the public path or null on failure.
     */
    public function saveUploadedFile(UploadedFile $file, string $eventId, string $userId): ?string
    {
        $key = $eventId . '-' . $userId;
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

        $filename = $key . '.' . $ext;

        // Remove old image files with different extension
        foreach (['jpg', 'png', 'webp', 'gif'] as $oldExt) {
            $oldFile = $this->uploadDir . '/' . $key . '.' . $oldExt;
            if ($oldExt !== $ext && file_exists($oldFile)) {
                @unlink($oldFile);
            }
        }

        try {
            $file->move($this->uploadDir, $filename);
        } catch (\Throwable) {
            return null;
        }

        return '/uploads/events/' . $filename . '?v=' . time();
    }

    /**
     * Deletes the stored photo files of a participant for an event.
     */
    public function delete(string $eventId, string $userId): void
    {
        foreach (['jpg', 'png', 'webp', 'gif'] as $ext) {
            $file = $this->uploadDir . '/' . $eventId . '-' . $userId . '.' . $ext;
            if (file_exists($file)) {
                @unlink($file);
            }
        }
    }
}
