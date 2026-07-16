<?php

declare(strict_types=1);

namespace App\Notification;

/**
 * Le catalogue des notifications : un cas = une ligne dans les préférences,
 * une pastille dans le fil, une raison d'envoyer un push.
 *
 * La valeur d'un cas est stockée telle quelle (colonne `type` de Notification,
 * clés de `User::$notifPrefs`) : ne pas la renommer sans migration.
 *
 * Les préférences ne coupent que le push — le fil in-app reste exhaustif, pour
 * qu'une notif désactivée soit silencieuse et non perdue. Voir NotificationDispatcher.
 */
enum NotificationType: string
{
    case FriendRequest = 'friend_request';
    case FriendAccepted = 'friend_accepted';
    case FriendTaggedInEvent = 'friend_tagged_in_event';
    case FriendActivity = 'friend_activity';
    case FriendSameEvent = 'friend_same_event';
    case FriendReaction = 'friend_reaction';
    case EventDay = 'event_day';
    case EventCompletion = 'event_completion';
    case EventAnniversary = 'event_anniversary';
    case RewindAvailable = 'rewind_available';

    /** Intitulé de la ligne dans les préférences */
    public function label(): string
    {
        return match ($this) {
            self::FriendRequest => 'Demandes d\'ami',
            self::FriendAccepted => 'Demandes d\'ami acceptées',
            self::FriendTaggedInEvent => 'Quand un ami te tague',
            self::FriendActivity => 'Activité de tes amis',
            self::FriendSameEvent => 'Un ami au même événement',
            self::FriendReaction => 'Réactions à tes événements',
            self::EventDay => 'Rappel le jour J',
            self::EventCompletion => 'Rappel de complétion',
            self::EventAnniversary => 'Anniversaire d\'un événement',
            self::RewindAvailable => 'Ton Rewind est prêt',
        };
    }

    /** Sous-titre : ce que l'utilisateur recevra concrètement */
    public function description(): string
    {
        return match ($this) {
            self::FriendRequest => 'Quelqu\'un veut t\'ajouter en ami',
            self::FriendAccepted => 'Ta demande d\'ami a été acceptée',
            self::FriendTaggedInEvent => 'Un ami t\'ajoute comme accompagnant sur un événement',
            self::FriendActivity => 'Un ami ajoute un événement ou publie un souvenir',
            self::FriendSameEvent => 'On te demande si vous y allez ensemble',
            self::FriendReaction => 'Un ami réagit à un événement de ton journal',
            self::EventDay => 'Le matin de ton événement',
            self::EventCompletion => 'Le lendemain, pour noter et raconter',
            self::EventAnniversary => 'Le jour où tu y étais, un an plus tôt',
            self::RewindAvailable => 'Ton bilan de l\'année vient d\'être publié',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::FriendRequest => '👥',
            self::FriendAccepted => '✅',
            self::FriendTaggedInEvent => '🏷️',
            self::FriendActivity => '📣',
            self::FriendSameEvent => '🎟️',
            self::FriendReaction => '🔥',
            self::EventDay => '📅',
            self::EventCompletion => '⭐',
            self::EventAnniversary => '🕰️',
            self::RewindAvailable => '🎁',
        };
    }

    /** Fond de la pastille dans le fil de notifications */
    public function accent(): string
    {
        return match ($this) {
            self::FriendRequest => 'rgba(96,165,250,0.15)',
            self::FriendAccepted => 'rgba(61,220,151,0.15)',
            self::FriendTaggedInEvent => 'rgba(176,96,255,0.15)',
            self::FriendActivity => 'rgba(96,165,250,0.15)',
            self::FriendSameEvent => 'rgba(176,96,255,0.15)',
            self::FriendReaction => 'rgba(251,146,60,0.15)',
            self::EventDay => 'rgba(251,191,36,0.15)',
            self::EventCompletion => 'rgba(251,191,36,0.15)',
            self::EventAnniversary => 'rgba(176,96,255,0.15)',
            self::RewindAvailable => 'rgba(176,96,255,0.15)',
        };
    }

    /**
     * Les rappels programmés partent à l'heure choisie par l'utilisateur
     * (`notifCompletionTime`) ; les autres partent sur le coup.
     */
    public function isScheduled(): bool
    {
        return in_array($this, [self::EventDay, self::EventCompletion, self::EventAnniversary], true);
    }

    /**
     * Regroupées par section dans les préférences.
     *
     * @return array<string, list<self>>
     */
    public static function groups(): array
    {
        return [
            'Amis' => [self::FriendRequest, self::FriendAccepted, self::FriendTaggedInEvent],
            'Activité' => [self::FriendActivity, self::FriendSameEvent, self::FriendReaction],
            'Rappels' => [self::EventDay, self::EventCompletion, self::EventAnniversary],
            'Rewind' => [self::RewindAvailable],
        ];
    }

    /**
     * Tout est activé par défaut : on ne fait pas rater une notif à quelqu'un
     * qui n'a jamais ouvert les réglages. Le refus se fait au niveau du
     * navigateur (permission push) ou en décochant ici.
     *
     * @return array<string, bool>
     */
    public static function defaults(): array
    {
        $defaults = [];
        foreach (self::cases() as $case) {
            $defaults[$case->value] = true;
        }

        return $defaults;
    }
}
