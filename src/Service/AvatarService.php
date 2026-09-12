<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Image\ImageProcessor;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * La photo de profil. Même règles que la photo d'événement : nom aléatoire (l'id
 * de l'utilisateur est public, il n'a rien à faire dans une URL de fichier),
 * réencodage en WebP 400 px qui retire l'EXIF, ancienne photo effacée.
 */
class AvatarService
{
    public const MAX_SIDE = 400;

    private string $uploadDir;

    public function __construct(
        private readonly ImageProcessor $images,
        KernelInterface $kernel,
    ) {
        $this->uploadDir = $kernel->getProjectDir() . '/public/uploads/avatars';
    }

    /** Enregistre la photo et renvoie son chemin public, ou null si le fichier est refusé. */
    public function save(UploadedFile $file, User $user): ?string
    {
        if (!$this->images->accepts($file->getMimeType())) {
            return null;
        }

        return $this->store($file->getPathname(), $user);
    }

    /** Télécharge la photo Google et l'enregistre comme un téléversement. */
    public function downloadFromUrl(string $url, User $user): ?string
    {
        $context = stream_context_create([
            'http' => ['timeout' => 5, 'user_agent' => 'IWasThere/1.0'],
            'ssl'  => ['verify_peer' => true],
        ]);

        $data = @file_get_contents($url, false, $context);
        if ($data === false || strlen($data) < 100) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'avatar');
        if ($tmp === false) {
            return null;
        }
        try {
            file_put_contents($tmp, $data);

            return $this->store($tmp, $user);
        } finally {
            @unlink($tmp);
        }
    }

    /** Efface le fichier de la photo actuelle, s'il y en a une. */
    public function delete(User $user): void
    {
        $file = $this->fileOf($user->getAvatarUrl());
        if ($file !== null && file_exists($file)) {
            @unlink($file);
        }
    }

    private function store(string $source, User $user): ?string
    {
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }

        $name = bin2hex(random_bytes(16)) . '.webp';
        if (!$this->images->toWebp($source, $this->uploadDir . '/' . $name, self::MAX_SIDE)) {
            return null;
        }

        $this->delete($user);

        return '/uploads/avatars/' . $name;
    }

    private function fileOf(?string $avatarUrl): ?string
    {
        if ($avatarUrl === null || !str_starts_with($avatarUrl, '/uploads/avatars/')) {
            return null;
        }
        $base = basename((string) strtok($avatarUrl, '?'));
        if ($base === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $base)) {
            return null;
        }

        return $this->uploadDir . '/' . $base;
    }
}
