<?php

declare(strict_types=1);

namespace App\Reaction;

/**
 * Ce qu'on accepte comme réaction — n'importe quel emoji, pas une liste fermée.
 *
 * Les suggestions ne sont qu'un raccourci de saisie : rien ne les privilégie en
 * base, un emoji tapé à la main est une réaction comme une autre. C'est pour ça
 * que le caractère lui-même est stocké, et non un code : le catalogue n'existe pas.
 *
 * La colonne est en utf8mb4_unicode_ci, qui distingue bien les emojis entre eux
 * mais ignore le sélecteur de variation (U+FE0F) : '❤️' et '❤' y sont une seule et
 * même valeur. C'est le comportement voulu — et c'est la base qui regroupe, pas
 * PHP, sans quoi les deux formes feraient deux pastilles pour le même cœur.
 */
final class ReactionEmoji
{
    /** Proposées dans le sélecteur, dans cet ordre. */
    public const SUGGESTIONS = ['🔥', '❤️', '🎉', '😂', '🤩', '👏', '🙌', '😭', '🎸', '🏆'];

    /**
     * Le nombre de points de code d'une réaction. Large de quoi laisser passer un
     * drapeau à balises (8) ou une famille (7), assez court pour qu'on ne puisse
     * pas faire tenir une phrase dans une pastille.
     */
    private const MAX_CODEPOINTS = 8;

    /**
     * Valide une saisie et rend l'emoji à stocker, ou null si ce n'en est pas un.
     *
     * Trois conditions : un seul grapheme (« 🔥🔥 » n'est pas une réaction), une
     * longueur bornée, et au moins un caractère réellement pictographique — ce
     * dernier point est ce qui écarte les lettres et les chiffres. \p{Emoji} ne
     * ferait pas l'affaire : les chiffres 0-9 le portent, « 7 » passerait.
     *
     * Les drapeaux (🇫🇷) sont admis à part : ils sont faits d'indicateurs
     * régionaux, qui ne sont pas pictographiques.
     */
    public static function normalize(string $input): ?string
    {
        $emoji = trim($input);

        if ($emoji === '' || !mb_check_encoding($emoji, 'UTF-8')) {
            return null;
        }

        if (grapheme_strlen($emoji) !== 1 || mb_strlen($emoji, 'UTF-8') > self::MAX_CODEPOINTS) {
            return null;
        }

        $pictographic = preg_match('/\p{Extended_Pictographic}/u', $emoji) === 1;
        $flag = preg_match('/^\p{Regional_Indicator}{2}$/u', $emoji) === 1;

        return $pictographic || $flag ? $emoji : null;
    }
}
