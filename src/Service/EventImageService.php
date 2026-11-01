<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EventParticipation;
use App\Image\ImageProcessor;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * La photo qu'un participant attache à un événement.
 *
 * Le fichier porte un nom aléatoire et non les ids de l'événement et de
 * l'utilisateur : ceux-ci sont visibles de tout compte connecté (page événement,
 * liens de profil), et le dossier est servi statiquement — avec des ids dans le
 * nom, la photo d'un journal « privé » se devinait. Le nom est stocké en base
 * (imageUrl), c'est la seule façon de le retrouver.
 *
 * Deux fichiers par photo : la version pleine (1600 px) pour la page événement et
 * le ticket, une miniature (480 px) pour les cartes du feed et de l'accueil.
 */
class EventImageService
{
    public const MAX_SIDE = 1600;
    public const THUMB_SIDE = 480;

    private string $uploadDir;

    public function __construct(
        private readonly ImageProcessor $images,
        KernelInterface $kernel,
    ) {
        $this->uploadDir = $kernel->getProjectDir() . '/public/uploads/events';
    }

    /**
     * Enregistre la photo et renvoie son chemin public, ou null si le fichier
     * n'est pas une image acceptée. L'ancienne photo de la participation est effacée.
     */
    public function save(UploadedFile $file, EventParticipation $participation): ?string
    {
        if (!$this->images->accepts($file->getMimeType())) {
            return null;
        }

        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }

        $name = bin2hex(random_bytes(16));
        $full = $this->uploadDir . '/' . $name . '.webp';
        $thumb = $this->uploadDir . '/' . $name . '-thumb.webp';

        if (!$this->images->toWebp($file->getPathname(), $full, self::MAX_SIDE)) {
            return null;
        }
        if (!$this->images->toWebp($file->getPathname(), $thumb, self::THUMB_SIDE, 78)) {
            @unlink($full);

            return null;
        }

        $this->delete($participation);

        return '/uploads/events/' . $name . '.webp';
    }

    /** Efface les fichiers de la photo de cette participation, s'il y en a. */
    public function delete(EventParticipation $participation): void
    {
        foreach ($this->filesOf($participation->getImageUrl()) as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * La miniature correspondant à un chemin public, ou le chemin lui-même pour
     * les photos d'avant les miniatures (nommées par un UUID, sans « -thumb »).
     */
    public function thumbUrl(?string $imageUrl): ?string
    {
        if ($imageUrl === null) {
            return null;
        }
        $thumb = preg_replace('/\.webp(\?.*)?$/', '-thumb.webp', $imageUrl);
        if ($thumb === $imageUrl || $thumb === null) {
            return $imageUrl;
        }
        $path = $this->uploadDir . '/' . basename(strtok($thumb, '?'));

        return file_exists($path) ? $thumb : $imageUrl;
    }

    /** @return list<string> chemins disque de la photo et de sa miniature */
    private function filesOf(?string $imageUrl): array
    {
        if ($imageUrl === null) {
            return [];
        }
        $base = basename((string) strtok($imageUrl, '?'));
        // Refus de tout ce qui n'est pas un nom de fichier plat : le chemin vient de la base,
        // mais il n'y a aucune raison de laisser passer un « ../ » si elle a été altérée.
        if ($base === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $base)) {
            return [];
        }
        $files = [$this->uploadDir . '/' . $base];
        if (str_ends_with($base, '.webp')) {
            $files[] = $this->uploadDir . '/' . substr($base, 0, -5) . '-thumb.webp';
        }

        return $files;
    }
}
