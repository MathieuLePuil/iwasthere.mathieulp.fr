<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Event;
use App\Entity\EventParticipation;
use App\Entity\User;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationType;
use App\Repository\EventParticipationRepository;
use App\Repository\UserRepository;
use App\Service\SetlistFmService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Les rappels programmés, tournés chaque minute par le cron : chaque utilisateur
 * a une heure d'envoi, on ne traite que ceux dont l'heure tombe maintenant.
 *
 *  - jour J     : le matin même, « c'est aujourd'hui »
 *  - complétion : les jours suivants, tant que la fiche n'est pas notée
 *
 * Contrairement aux notifications sociales, un rappel désactivé n'est pas produit
 * du tout — le rappel *est* la notification, il n'y a pas de fait sous-jacent à
 * archiver dans le fil. D'où le `wantsPush` en amont, et non dans le dispatcher.
 */
#[AsCommand(
    name: 'app:notifications:send-reminders',
    description: 'Send day-of and completion reminders to users whose reminder time is now',
)]
class SendEventRemindersCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly EventParticipationRepository $participationRepo,
        private readonly NotificationDispatcher $notifier,
        private readonly SetlistFmService $setlistFmService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Les utilisateurs saisissent leur heure de rappel en heure française ; le serveur est en UTC
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        $currentTime = $now->format('H:i');
        $today = $now->format('Y-m-d');

        $sent = 0;
        foreach ($this->userRepo->findAll() as $user) {
            if (($user->getNotifCompletionTime() ?? '08:00') !== $currentTime) {
                continue;
            }

            if ($user->wantsPush(NotificationType::EventDay)) {
                $sent += $this->remindDayOf($user, $io) ? 1 : 0;
            }

            if ($user->wantsPush(NotificationType::EventCompletion)) {
                $sent += $this->remindCompletion($user, $today, $io) ? 1 : 0;
            }

            if ($user->wantsPush(NotificationType::EventAnniversary)) {
                $sent += $this->remindAnniversary($user, $now, $io) ? 1 : 0;
            }
        }

        $io->success(sprintf('%d reminder(s) sent', $sent));

        return Command::SUCCESS;
    }

    /** « C'est aujourd'hui » — le matin de l'événement. */
    private function remindDayOf(User $user, SymfonyStyle $io): bool
    {
        $today = $this->participationRepo->findToday($user);
        if ($today === []) {
            return false;
        }

        $first = $today[0]->getEvent();
        $count = count($today);

        if ($count === 1) {
            $title = 'Aujourd\'hui : ' . $this->eventName($first);
            $body = $this->placeAndTime($first) ?? 'C\'est aujourd\'hui !';
            $url = '/event/' . $first->getId();
        } else {
            $title = $count . ' événements aujourd\'hui';
            $body = implode(', ', array_map(fn (EventParticipation $p) => $this->eventName($p->getEvent()), $today));
            $url = '/';
        }

        // Une seule fois par jour, quoi qu'il arrive : le cron tourne chaque
        // minute et un double passage rejouerait l'envoi
        $created = $this->notifier->dispatch(
            $user,
            NotificationType::EventDay,
            $title,
            $body,
            $url,
            dedupeKey: 'day:' . $first->getDate()->format('Y-m-d'),
        );

        if ($created) {
            $io->writeln(sprintf('  📅 %s (%d aujourd\'hui)', $user->getUsername(), $count));
        }

        return $created;
    }

    /** « Raconte-nous » — tant que la fiche d'un événement passé n'est pas notée. */
    private function remindCompletion(User $user, string $today, SymfonyStyle $io): bool
    {
        // Sans cela, les participations restent "upcoming" tant que l'utilisateur
        // n'a pas ouvert l'app, et findPendingReminders ne les voit pas
        $this->participationRepo->updateStaleUpcoming($user);

        $reminders = $this->participationRepo->findPendingReminders($user);
        if ($reminders === []) {
            return false;
        }

        $count = count($reminders);
        $first = $reminders[0]->getEvent();

        $created = $this->notifier->dispatch(
            $user,
            NotificationType::EventCompletion,
            'Comment s\'était ce concert ?',
            $count === 1
                ? 'Tu n\'as pas encore rempli ta fiche pour ' . $this->eventName($first) . '.'
                : sprintf('%d événements attendent ta note et tes commentaires.', $count),
            $count === 1 ? '/event/' . $first->getId() . '/complete' : '/',
            dedupeKey: 'completion:' . $today,
        );

        if ($created) {
            $io->writeln(sprintf('  ⭐ %s (%d fiche(s))', $user->getUsername(), $count));
            $this->importSetlists($reminders, $io);
        }

        return $created;
    }

    /**
     * « Il y a un an » — un événement vécu un jour comme aujourd'hui.
     *
     * Le libellé ne dit pas « ce soir » : le rappel part à l'heure choisie par
     * l'utilisateur (08h00 par défaut), « ce soir » serait faux la plupart du temps.
     */
    private function remindAnniversary(User $user, \DateTimeImmutable $now, SymfonyStyle $io): bool
    {
        $anniversaries = $this->participationRepo->findAnniversaries($user, $now);
        if ($anniversaries === []) {
            return false;
        }

        // Le plus récent d'abord (tri de la requête) : entre un souvenir d'un an et un
        // de dix ans le même jour, c'est celui d'un an qui parle le plus.
        $first = $anniversaries[0]->getEvent();
        $years = (int) $now->format('Y') - (int) $first->getDate()->format('Y');
        $count = count($anniversaries);

        $title = $years === 1 ? 'Il y a un an, jour pour jour…' : sprintf('Il y a %d ans, jour pour jour…', $years);
        $body = $this->eventName($first);
        if ($place = $this->placeAndTime($first)) {
            $body .= ', ' . $place;
        }
        if ($count > 1) {
            $body .= sprintf(' (+%d autre%s souvenir%s ce jour-là)', $count - 1, $count > 2 ? 's' : '', $count > 2 ? 's' : '');
        }

        $created = $this->notifier->dispatch(
            $user,
            NotificationType::EventAnniversary,
            $title,
            $body,
            '/event/' . $first->getId(),
            // Une seule fois par jour : le cron tourne chaque minute
            dedupeKey: 'anniversary:' . $now->format('Y-m-d'),
        );

        if ($created) {
            $io->writeln(sprintf('  🕰️ %s (%d souvenir(s), il y a %d an(s))', $user->getUsername(), $count, $years));
        }

        return $created;
    }

    /** @param EventParticipation[] $reminders */
    private function importSetlists(array $reminders, SymfonyStyle $io): void
    {
        foreach ($reminders as $reminder) {
            $event = $reminder->getEvent();
            if (!empty($event->getSetlist())) {
                continue;
            }
            if ($this->setlistFmService->tryImportSetlist($event)) {
                $io->writeln(sprintf('    ♪ Setlist importée : %s', $event->getArtistName()));
            }
            // Respect API rate limit (2 req/s on free plan)
            usleep(600_000);
        }
    }

    /** « à l'Olympia à 21h », selon ce qui est renseigné ; null si on ne sait rien */
    private function placeAndTime(Event $event): ?string
    {
        $parts = [];
        if ($venue = $event->getVenue()) {
            $parts[] = 'à ' . $venue->getName();
        }
        // L'heure n'est affichée que si elle a été saisie : sinon c'est une
        // valeur par défaut, qu'on ne présente pas comme un horaire réel
        if ($startTime = $event->getStartTime()) {
            $parts[] = 'à ' . ($startTime->format('i') === '00'
                ? $startTime->format('G') . 'h'
                : $startTime->format('G\hi'));
        }

        return $parts === [] ? null : implode(' ', $parts);
    }

    private function eventName(Event $event): string
    {
        return $event->getArtistName()
            ?? $event->getTournamentName()
            ?? $event->getTeams()
            ?? 'ton événement';
    }
}
