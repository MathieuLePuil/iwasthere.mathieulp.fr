<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EventParticipation;
use App\Entity\User;
use App\Repository\EventParticipationRepository;
use App\Repository\FriendRepository;

/**
 * Tout ce que le compte contient, en un document JSON : le profil, chaque
 * participation avec son événement (souvenir, accompagnants, photo), les amis.
 * C'est la portabilité du RGPD (art. 20), et aussi un vrai service pour un
 * journal — on veut pouvoir l'emporter.
 */
final class DataExportService
{
    public function __construct(
        private readonly EventParticipationRepository $participations,
        private readonly FriendRepository $friends,
    ) {}

    /** @return array<string, mixed> */
    public function export(User $user, string $baseUrl): array
    {
        $events = array_map(
            fn (EventParticipation $p) => $this->participation($p, $baseUrl),
            $this->participations->findAllByUser($user),
        );

        $friends = [];
        foreach ($this->friends->findConfirmedFriends($user) as $rel) {
            $other = $rel->getOwner()->getId()->equals($user->getId()) ? $rel->getFriendUser() : $rel->getOwner();
            if ($other !== null) {
                $friends[] = ['username' => $other->getUsername(), 'displayName' => $other->getDisplayName()];
            }
        }

        return [
            'exportedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'profile' => [
                'username' => $user->getUsername(),
                'displayName' => $user->getDisplayName(),
                'email' => $user->getEmail(),
                'bio' => $user->getBio(),
                'avatar' => $user->getAvatarUrl() ? $baseUrl . strtok($user->getAvatarUrl(), '?') : null,
                'favoriteTeams' => $user->getFavoriteTeams(),
                'privacy' => $user->getPrivacySettings(),
                'notificationPreferences' => $user->getNotifPrefs(),
                'createdAt' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ],
            'events' => $events,
            'friends' => $friends,
        ];
    }

    /** @return array<string, mixed> */
    private function participation(EventParticipation $p, string $baseUrl): array
    {
        $e = $p->getEvent();

        return [
            'event' => [
                'category' => $e->getCategory(),
                'type' => $e->getType(),
                'date' => $e->getDate()->format('Y-m-d'),
                'startTime' => $e->getStartTime()?->format('H:i'),
                'artist' => $e->getArtistName(),
                'tour' => $e->getTourName(),
                'tournament' => $e->getTournamentName(),
                'teams' => $e->getTeams(),
                'finalScore' => $e->getFinalScore(),
                'winner' => $e->getScoreline()['winner'] ?? null,
                'venue' => $e->getVenue() ? [
                    'name' => $e->getVenue()->getName(),
                    'address' => $e->getVenue()->getAddress() ?: null,
                ] : null,
                'setlist' => array_column($e->getSetlistNormalized(), 'name'),
                'encores' => array_column($e->getSetlistEncoresNormalized(), 'name'),
                'setlistSource' => $e->getSetlistSource(),
                'setlistUrl' => $e->getSetlistUrl(),
            ],
            'rating' => $p->getRating(),
            'comment' => $p->getComment(),
            'durationMinutes' => $p->getDuration(),
            'companions' => array_map(fn (array $f) => ($f['type'] ?? '') === 'app'
                ? ['username' => $f['username'] ?? null, 'displayName' => $f['displayName'] ?? null]
                : ['name' => $f['name'] ?? null], $p->getFriends()),
            'photo' => $p->getImageUrl() ? $baseUrl . strtok($p->getImageUrl(), '?') : null,
            'addedAt' => $p->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
