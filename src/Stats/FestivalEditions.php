<?php

declare(strict_types=1);

namespace App\Stats;

use App\Entity\EventParticipation;

/**
 * Regroupe les concerts vus en festival par édition.
 *
 * Un festival est archivé comme une suite de concerts — quatre groupes vus au
 * Hellfest samedi, c'est quatre fiches. Les compter un par un donnerait « 52
 * festivals » à quelqu'un qui en a fait cinq : partout où l'on parle d'un nombre
 * de festivals, c'est d'éditions qu'il s'agit, et elles se déduisent d'ici.
 *
 * Rien n'identifie une édition en base : le nom du festival n'est pas stocké, seul
 * le lieu l'est, et il tient donc lieu de nom. Deux fiches au même endroit
 * appartiennent à la même édition tant que les dates se suivent — d'où le
 * regroupement par écart plutôt que par mois ou par année civile, qui couperaient
 * en deux un festival du 30 juin au 2 juillet ou du 31 décembre au 1er janvier.
 */
final class FestivalEditions
{
    /**
     * Au-delà de cet écart entre deux dates au même lieu, c'est une autre édition.
     *
     * Large exprès : il couvre les jours non fréquentés au milieu d'une édition
     * (venu le 30 juin puis le 2 juillet) comme les festivals à deux week-ends,
     * tout en restant très loin des ~365 jours qui séparent deux éditions.
     */
    private const ECART_MAX_JOURS = 10;

    /**
     * Les éditions, chacune tenant ses participations triées par date.
     *
     * @param EventParticipation[] $parts
     *
     * @return list<list<EventParticipation>>
     */
    public static function group(array $parts): array
    {
        $parLieu = [];
        foreach ($parts as $p) {
            $e = $p->getEvent();
            if ($e->getCategory() !== 'music' || $e->getType() !== 'festival') {
                continue;
            }
            $parLieu[$e->getVenue()?->getName() ?? 'Inconnu'][] = $p;
        }

        $editions = [];
        foreach ($parLieu as $lieuParts) {
            usort($lieuParts, fn(EventParticipation $a, EventParticipation $b) => $a->getEvent()->getDate() <=> $b->getEvent()->getDate());

            $edition = [];
            $veille = null;
            foreach ($lieuParts as $p) {
                $date = $p->getEvent()->getDate();
                if ($veille !== null && (int) $veille->diff($date)->days > self::ECART_MAX_JOURS) {
                    $editions[] = $edition;
                    $edition = [];
                }
                $edition[] = $p;
                $veille = $date;
            }
            $editions[] = $edition;
        }

        return $editions;
    }

    /**
     * Le nom d'une édition, c'est celui de son lieu.
     *
     * @param list<EventParticipation> $edition
     */
    public static function name(array $edition): string
    {
        return $edition[0]->getEvent()->getVenue()?->getName() ?? 'Inconnu';
    }

    /**
     * L'année d'une édition est celle de son premier jour : un festival à cheval
     * sur le Nouvel An reste l'édition de l'année où il a commencé.
     *
     * @param list<EventParticipation> $edition
     */
    public static function year(array $edition): int
    {
        return (int) $edition[0]->getEvent()->getDate()->format('Y');
    }
}
