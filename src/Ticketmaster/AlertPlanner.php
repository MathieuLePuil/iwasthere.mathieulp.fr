<?php

declare(strict_types=1);

namespace App\Ticketmaster;

/**
 * Les règles de calage des alertes sur une ouverture de billetterie. Pur :
 * une ouverture, un « maintenant », deux options → les échéances à planifier.
 *
 *  - J-1 : 24 h avant l'ouverture. Si l'événement arrive au catalogue (ou la
 *    veille est posée) à moins de 24 h : « ouverture imminente », envoyée tout
 *    de suite s'il reste plus de 2 h ; en deçà, rien — l'alerte H-1 suffit.
 *  - H-1 : 1 h avant. À moins d'une heure, tout de suite : quelqu'un qui pose
 *    une veille trente minutes avant veut bien l'alerte.
 *  - Ouverture passée : rien. La veille reste, mais aucune alerte ne partira ;
 *    l'interface le dit.
 */
final class AlertPlanner
{
    public const J1_LEAD = 24 * 3600;
    public const H1_LEAD = 3600;

    /** Sous ce délai restant, pas de J-1 « imminente » : on laisse la H-1 jouer. */
    public const IMMINENT_MIN_LEAD = 2 * 3600;

    /**
     * @return array<string, \DateTimeImmutable> type (valeur de WatchNotificationType) → instant d'envoi
     */
    public static function plan(\DateTimeImmutable $opensAt, \DateTimeImmutable $now, bool $j1, bool $h1): array
    {
        $remaining = $opensAt->getTimestamp() - $now->getTimestamp();
        if ($remaining <= 0) {
            return [];
        }

        $plan = [];
        if ($j1) {
            if ($remaining >= self::J1_LEAD) {
                $plan[WatchNotificationType::J1->value] = $opensAt->sub(new \DateInterval('PT24H'));
            } elseif ($remaining > self::IMMINENT_MIN_LEAD) {
                $plan[WatchNotificationType::J1->value] = $now;
            }
        }
        if ($h1) {
            $plan[WatchNotificationType::H1->value] = $remaining >= self::H1_LEAD
                ? $opensAt->sub(new \DateInterval('PT1H'))
                : $now;
        }

        return $plan;
    }

    /** Une alerte prévue à cet instant, pour cette ouverture, est-elle une « ouverture imminente » ? */
    public static function isImminent(\DateTimeImmutable $opensAt, \DateTimeImmutable $scheduledFor): bool
    {
        return $opensAt->getTimestamp() - $scheduledFor->getTimestamp() < self::J1_LEAD - 60;
    }
}
