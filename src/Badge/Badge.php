<?php

declare(strict_types=1);

namespace App\Badge;

/**
 * Le catalogue des succès. Un cas = une pastille sur le profil.
 *
 * Chaque badge lit une clé de EventParticipationRepository::computeStats() et se
 * compare à un palier : rien n'est stocké en base, un badge se recalcule à
 * l'affichage. C'est ce qui permet d'en ajouter un sans migration ni rattrapage —
 * et ce qui fait qu'un badge se reperd si une fiche est supprimée, ce qui est le
 * comportement voulu : il décrit l'historique, il ne le décore pas.
 *
 * La valeur d'un cas n'est stockée nulle part, elle peut donc être renommée.
 */
enum Badge: string
{
    // Concerts
    case Concerts1 = 'concerts_1';
    case Concerts10 = 'concerts_10';
    case Concerts50 = 'concerts_50';
    case Concerts100 = 'concerts_100';

    // Festivals
    case Festivals1 = 'festivals_1';
    case Festivals5 = 'festivals_5';
    case Festivals15 = 'festivals_15';

    // Lieux différents
    case Venues5 = 'venues_5';
    case Venues15 = 'venues_15';
    case Venues30 = 'venues_30';

    // Chansons vues en live
    case Songs10 = 'songs_10';
    case Songs50 = 'songs_50';
    case Songs250 = 'songs_250';

    // Jours consécutifs avec un événement
    case DayStreak2 = 'day_streak_2';
    case DayStreak3 = 'day_streak_3';
    case DayStreak5 = 'day_streak_5';

    // Mois consécutifs avec au moins un événement
    case MonthStreak3 = 'month_streak_3';
    case MonthStreak6 = 'month_streak_6';
    case MonthStreak12 = 'month_streak_12';

    /** La clé de computeStats() que ce badge mesure. */
    public function metric(): string
    {
        return match ($this) {
            self::Concerts1, self::Concerts10, self::Concerts50, self::Concerts100 => 'concerts',
            self::Festivals1, self::Festivals5, self::Festivals15 => 'festivals',
            self::Venues5, self::Venues15, self::Venues30 => 'venues_count',
            self::Songs10, self::Songs50, self::Songs250 => 'songs_count',
            self::DayStreak2, self::DayStreak3, self::DayStreak5 => 'max_streak',
            self::MonthStreak3, self::MonthStreak6, self::MonthStreak12 => 'max_month_streak',
        };
    }

    /** Le palier à atteindre sur cette mesure. */
    public function target(): int
    {
        return match ($this) {
            self::Concerts1 => 1,
            self::Concerts10 => 10,
            self::Concerts50 => 50,
            self::Concerts100 => 100,
            self::Festivals1 => 1,
            self::Festivals5 => 5,
            self::Festivals15 => 15,
            self::Venues5 => 5,
            self::Venues15 => 15,
            self::Venues30 => 30,
            self::Songs10 => 10,
            self::Songs50 => 50,
            self::Songs250 => 250,
            self::DayStreak2 => 2,
            self::DayStreak3 => 3,
            self::DayStreak5 => 5,
            self::MonthStreak3 => 3,
            self::MonthStreak6 => 6,
            self::MonthStreak12 => 12,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Concerts1 => 'Premier concert',
            self::Concerts10 => 'Habitué',
            self::Concerts50 => 'Pilier',
            self::Concerts100 => 'Centenaire',
            self::Festivals1 => 'Premier festival',
            self::Festivals5 => 'Festivalier',
            self::Festivals15 => 'Camp de base',
            self::Venues5 => 'Vagabond',
            self::Venues15 => 'Bourlingueur',
            self::Venues30 => 'Partout chez soi',
            self::Songs10 => 'Premiers refrains',
            self::Songs50 => 'Beau répertoire',
            self::Songs250 => 'Discothèque vivante',
            self::DayStreak2 => 'Doublé',
            self::DayStreak3 => 'Trois jours durant',
            self::DayStreak5 => 'Marathon',
            self::MonthStreak3 => 'En rythme',
            self::MonthStreak6 => 'Six mois sans faillir',
            self::MonthStreak12 => 'Année pleine',
        };
    }

    /** Ce qu'il faut faire, à la deuxième personne comme le reste de l'app. */
    public function description(): string
    {
        return match ($this) {
            self::Concerts1 => 'Ton premier concert',
            self::Concerts10 => '10 concerts',
            self::Concerts50 => '50 concerts',
            self::Concerts100 => '100 concerts',
            self::Festivals1 => 'Ton premier festival',
            self::Festivals5 => '5 festivals',
            self::Festivals15 => '15 festivals',
            self::Venues5 => '5 lieux différents',
            self::Venues15 => '15 lieux différents',
            self::Venues30 => '30 lieux différents',
            self::Songs10 => '10 chansons vues en live',
            self::Songs50 => '50 chansons vues en live',
            self::Songs250 => '250 chansons vues en live',
            self::DayStreak2 => '2 jours d\'événements d\'affilée',
            self::DayStreak3 => '3 jours d\'événements d\'affilée',
            self::DayStreak5 => '5 jours d\'événements d\'affilée',
            self::MonthStreak3 => 'Un événement par mois, 3 mois de suite',
            self::MonthStreak6 => 'Un événement par mois, 6 mois de suite',
            self::MonthStreak12 => 'Un événement par mois, 12 mois de suite',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Concerts1, self::Concerts10, self::Concerts50, self::Concerts100 => '🎤',
            self::Festivals1, self::Festivals5, self::Festivals15 => '🎪',
            self::Venues5, self::Venues15, self::Venues30 => '📍',
            self::Songs10, self::Songs50, self::Songs250 => '🎶',
            self::DayStreak2, self::DayStreak3, self::DayStreak5 => '🔥',
            self::MonthStreak3, self::MonthStreak6, self::MonthStreak12 => '📆',
        };
    }

    /**
     * Regroupés par famille sur le profil. L'ordre des paliers dans chaque famille
     * est celui de la déclaration, donc croissant : BadgeService s'en sert pour
     * trouver le prochain palier à viser.
     *
     * @return array<string, list<self>>
     */
    public static function families(): array
    {
        return [
            'Concerts'   => [self::Concerts1, self::Concerts10, self::Concerts50, self::Concerts100],
            'Festivals'  => [self::Festivals1, self::Festivals5, self::Festivals15],
            'Lieux'      => [self::Venues5, self::Venues15, self::Venues30],
            'Chansons'   => [self::Songs10, self::Songs50, self::Songs250],
            'Assiduité'  => [self::DayStreak2, self::DayStreak3, self::DayStreak5],
            'Régularité' => [self::MonthStreak3, self::MonthStreak6, self::MonthStreak12],
        ];
    }
}
