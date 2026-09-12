<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Prépare une saisie utilisateur pour un LIKE : les jokers % et _ sont pris au
 * pied de la lettre. Sans ça, « % » seul renvoyait toute la table.
 */
final class LikeEscaper
{
    /** « %terme% », jokers échappés. */
    public static function contains(string $term): string
    {
        return '%' . self::escape($term) . '%';
    }

    public static function escape(string $term): string
    {
        return addcslashes($term, '%_\\');
    }
}
