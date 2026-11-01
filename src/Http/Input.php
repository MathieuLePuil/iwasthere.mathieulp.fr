<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\Uid\Uuid;

/**
 * Lecture défensive des champs de formulaire lus à la main (Request::request).
 *
 * Chaque méthode renvoie une valeur du bon type ou null : rien n'est jamais
 * passé tel quel à une entité ou à une requête. Une date illisible donnait une
 * exception (500), un id qui n'est pas un UUID aussi, un type hors catalogue
 * cassait les templates — ici tout ce qui est douteux devient null, et
 * l'appelant décide s'il refuse ou ignore.
 */
final class Input
{
    /** Une date « Y-m-d », entre 1900 et dans cinq ans. */
    public static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            return null;
        }
        $min = new \DateTimeImmutable('1900-01-01');
        $max = (new \DateTimeImmutable('today'))->modify('+5 years');

        return $date >= $min && $date <= $max ? $date : null;
    }

    /** Une heure « H:i ». */
    public static function time(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('!H:i', $value) ?: null;
    }

    /** Un entier borné ; null si vide, non numérique ou hors bornes. */
    public static function int(mixed $value, int $min, int $max): ?int
    {
        if (is_int($value)) {
            $n = $value;
        } elseif (is_string($value) && preg_match('/^\s*-?\d+\s*$/', $value)) {
            $n = (int) $value;
        } else {
            return null;
        }

        return $n >= $min && $n <= $max ? $n : null;
    }

    /** Un texte nettoyé (trim, sans caractères de contrôle), tronqué à $max ; null si vide. */
    public static function text(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $text = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '');
        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, $max);
    }

    /** Un UUID sous forme de chaîne, ou null. */
    public static function uuid(mixed $value): ?string
    {
        return is_string($value) && Uuid::isValid($value) ? $value : null;
    }

    /** Une liste de chaînes (un champ `name[]`) ; tout ce qui n'est pas une chaîne est écarté. */
    public static function strings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
