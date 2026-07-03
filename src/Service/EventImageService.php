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
     * Saves an uploaded file as the event's image, returns the public path or null on failure.
     */
    public function saveUploadedFile(UploadedFile $file, string $eventId): ?string
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

        $filename = $eventId . '.' . $ext;

        // Remove old image files with different extension
        foreach (['jpg', 'png', 'webp', 'gif'] as $oldExt) {
            $oldFile = $this->uploadDir . '/' . $eventId . '.' . $oldExt;
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
     * Deletes the stored image files for an event.
     */
    public function delete(string $eventId): void
    {
        foreach (['jpg', 'png', 'webp', 'gif'] as $ext) {
            $file = $this->uploadDir . '/' . $eventId . '.' . $ext;
            if (file_exists($file)) {
                @unlink($file);
            }
        }
    }
}
