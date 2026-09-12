<?php

declare(strict_types=1);

namespace App\Image;

/**
 * Réencode une image téléversée : redimensionnée, réorientée, en WebP.
 *
 * Réencoder plutôt que copier a trois effets voulus : la photo de téléphone de
 * 2 Mo devient une image de 100 Ko (elle est servie dans le feed, sur l'accueil,
 * sur le ticket), les métadonnées EXIF — dont la position GPS — ne sont jamais
 * écrites sur le disque, et un fichier qui n'est pas une vraie image est refusé
 * par GD quoi qu'en dise son type MIME.
 */
final class ImageProcessor
{
    private const ALLOWED = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function accepts(?string $mime): bool
    {
        return in_array($mime, self::ALLOWED, true);
    }

    /**
     * Écrit une version WebP de $source dans $target, au plus $maxSide pixels de
     * côté. Renvoie false si le fichier n'est pas une image lisible.
     */
    public function toWebp(string $source, string $target, int $maxSide, int $quality = 82): bool
    {
        $image = $this->load($source);
        if ($image === null) {
            return false;
        }

        $image = $this->orient($image, $source);

        $w = imagesx($image);
        $h = imagesy($image);
        $scale = min(1.0, $maxSide / max($w, $h));
        if ($scale < 1.0) {
            $resized = imagescale($image, (int) round($w * $scale), (int) round($h * $scale), IMG_BICUBIC);
            if ($resized !== false) {
                imagedestroy($image);
                $image = $resized;
            }
        }

        $ok = imagewebp($image, $target, $quality);
        imagedestroy($image);

        return $ok;
    }

    private function load(string $path): ?\GdImage
    {
        $info = @getimagesize($path);
        if ($info === false) {
            return null;
        }

        $image = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG  => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            IMAGETYPE_GIF  => @imagecreatefromgif($path),
            default        => false,
        };
        if ($image === false) {
            return null;
        }

        // Les PNG/GIF transparents gardent leur transparence en WebP
        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $image;
    }

    /** Applique la rotation EXIF avant de la perdre : sans ça, les photos iPhone arrivent couchées. */
    private function orient(\GdImage $image, string $path): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data($path);
        $orientation = (int) ($exif['Orientation'] ?? 1);

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => false,
        };
        if ($rotated === false) {
            return $image;
        }
        imagedestroy($image);

        return $rotated;
    }
}
