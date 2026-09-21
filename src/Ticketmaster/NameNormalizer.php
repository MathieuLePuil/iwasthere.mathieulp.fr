<?php

declare(strict_types=1);

namespace App\Ticketmaster;

use function Symfony\Component\String\u;

/**
 * La forme sous laquelle on cherche un nom d'événement : minuscules, sans
 * accents ni ponctuation, espaces simples.
 *
 * Le catalogue est bruité — « ARTISTE - NOM DE TOURNÉE - ARTISTE », « Artiste
 * (Complet) », « ARTISTE / Première partie » — et la saisie de l'utilisateur
 * l'est aussi. Les deux passent par ici, et l'index plein texte porte sur le
 * résultat.
 */
final class NameNormalizer
{
    public static function normalize(string $name): string
    {
        $ascii = u($name)->ascii()->lower()->toString();
        $words = preg_replace('/[^a-z0-9]+/', ' ', $ascii) ?? '';

        return trim(preg_replace('/\s+/', ' ', $words) ?? '');
    }

    /**
     * L'expression pour MATCH … AGAINST en mode booléen : chaque mot est requis
     * et peut être un préfixe (« indo » trouve « indochine »). Les mots de moins
     * de trois lettres sont laissés de côté — sous innodb_ft_min_token_size ils
     * ne sont pas indexés, les exiger ne renverrait rien. La recherche les
     * rattrape avec un LIKE sur la forme normalisée entière.
     *
     * @return string vide si aucun mot n'est exploitable par l'index
     */
    public static function booleanQuery(string $query): string
    {
        $terms = [];
        foreach (explode(' ', self::normalize($query)) as $word) {
            if (strlen($word) >= 3) {
                $terms[] = '+' . $word . '*';
            }
        }

        return implode(' ', $terms);
    }
}
