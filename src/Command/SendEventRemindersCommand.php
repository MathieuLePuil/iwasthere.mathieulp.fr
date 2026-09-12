<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Event;
use App\Entity\EventParticipation;
use App\Entity\User;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationType;
use App\Repository\EventParticipationRepository;
use App\Repository\NotificationRepository;
use App\Repository\UserRepository;
use App\Service\SetlistFmService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Les rappels programmés, tournés chaque minute par le cron : chaque utilisateur
 * a une heure d'envoi, on ne traite que ceux dont l'heure vient de tomber.
 *
 *  - jour J     : le matin même, « c'est aujourd'hui »
 *  - complétion : les jours suivants, tant que la fiche n'est pas notée
 *  - anniversaire : un événement vécu un jour comme aujourd'hui
 *
 * La fenêtre n'est pas la minute exacte mais l'heure de rappel plus quelques
 * minutes (self::CATCH_UP_MINUTES) : un cron sauté — serveur chargé, minute
 * perdue, lock encore tenu par le passage précédent — ne doit pas faire perdre
 * le rappel de la journée. Les clés de dédoublonnage étant journalières, un
 * rattrapage ne peut pas produire de second envoi.
 *
 * Contrairement aux notifications sociales, un rappel désactivé n'est pas produit
 * du tout — le rappel *est* la notification, il n'y a pas de fait sous-jacent à
 * archiver dans le fil. D'où le `wantsPush` en amont, et non dans le dispatcher.
 */
#[AsCommand(
    name: 'app:notifications:send-reminders',
    description: 'Send day-of, completion and anniversary reminders to users whose reminder time has just come',
)]
class SendEventRemindersCommand extends Command
{
    /** Largeur de la fenêtre de rattrapage, en minutes (0 = la minute pile) */
    private const CATCH_UP_MINUTES = 10;

    private bool $dryRun = false;

    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly EventParticipationRepository $participationRepo,
        private readonly NotificationRepository $notifRepo,
        private readonly NotificationDispatcher $notifier,
        private readonly SetlistFmService $setlistFmService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('time', 't', InputOption::VALUE_REQUIRED, 'Se placer à cette heure française (« 08:00 ») au lieu de maintenant')
            ->addOption('date', 'd', InputOption::VALUE_REQUIRED, 'Se placer à cette date (« 2026-09-17 ») au lieu d\'aujourd\'hui')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Limiter à un utilisateur (@pseudo), quelle que soit son heure de rappel')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Montrer ce qui partirait, sans rien écrire ni envoyer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->dryRun = (bool) $input->getOption('dry-run');

        // Les utilisateurs saisissent leur heure de rappel en heure française ; le serveur est en UTC
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        if ($date = $input->getOption('date')) {
            if (!($parsed = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $date, $now->getTimezone()))) {
                $io->error('--date attend une date au format AAAA-MM-JJ (par exemple 2026-09-17).');

                return Command::INVALID;
            }
            $now = $parsed->setTime((int) $now->format('G'), (int) $now->format('i'));
        }
        if ($time = $input->getOption('time')) {
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $time)) {
                $io->error('--time attend une heure au format HH:MM (par exemple 08:00).');

                return Command::INVALID;
            }
            [$h, $m] = array_map('intval', explode(':', (string) $time));
            $now = $now->setTime($h, $m);
        }
        $today = $now->format('Y-m-d');

        $users = $this->resolveUsers($input->getOption('user'), $now, $io);
        if ($users === null) {
            return Command::FAILURE;
        }

        if ($this->dryRun) {
            $io->note(sprintf('Simulation à %s — rien ne sera écrit ni envoyé.', $now->format('d/m/Y H:i')));
        }

        $sent = 0;
        foreach ($users as $user) {
            // Une donnée bancale sur un utilisateur ne doit pas priver tous les
            // suivants de leur rappel : passé la fenêtre de rattrapage, le cron
            // ne revient pas sur la journée
            try {
                if ($user->wantsPush(NotificationType::EventDay)) {
                    $sent += $this->remindDayOf($user, $now, $io) ? 1 : 0;
                }

                if ($user->wantsPush(NotificationType::EventCompletion)) {
                    $sent += $this->remindCompletion($user, $today, $io) ? 1 : 0;
                }

                if ($user->wantsPush(NotificationType::EventAnniversary)) {
                    $sent += $this->remindAnniversary($user, $now, $io) ? 1 : 0;
                }
            } catch (\Throwable $e) {
                $this->logger->error('Rappel non envoyé', ['user' => $user->getUsername(), 'exception' => $e]);
                $io->warning(sprintf('%s : %s', $user->getUsername(), $e->getMessage()));
            }
        }

        $io->success(sprintf('%d reminder(s) %s', $sent, $this->dryRun ? 'would be sent' : 'sent'));

        return Command::SUCCESS;
    }

    /**
     * Qui traiter : l'utilisateur nommé par --user, sinon ceux dont l'heure de
     * rappel vient de tomber (fenêtre de rattrapage comprise).
     *
     * @return User[]|null null si le pseudo demandé est introuvable
     */
    private function resolveUsers(?string $username, \DateTimeImmutable $now, SymfonyStyle $io): ?array
    {
        if ($username !== null) {
            $user = $this->userRepo->findOneByUsername(ltrim($username, '@'));
            if ($user === null) {
                $io->error(sprintf('Utilisateur « %s » introuvable.', $username));

                return null;
            }

            return [$user];
        }

        $window = [];
        for ($i = 0; $i <= self::CATCH_UP_MINUTES; $i++) {
            $window[] = $now->modify(sprintf('-%d minutes', $i))->format('H:i');
        }

        return $this->userRepo->findDueForReminders($window);
    }

    /**
     * Inscrit et pousse le rappel — ou, en simulation, dit seulement s'il serait
     * parti (la clé de dédoublonnage ayant déjà été consommée ou non).
     */
    private function send(User $user, NotificationType $type, string $title, string $body, string $url, string $dedupeKey): bool
    {
        if ($this->dryRun) {
            return !$this->notifRepo->existsForDedupeKey($user, $type->value, $dedupeKey);
        }

        return $this->notifier->dispatch($user, $type, $title, $body, $url, dedupeKey: $dedupeKey);
    }

    /** « C'est aujourd'hui » — le matin de l'événement. */
    private function remindDayOf(User $user, \DateTimeImmutable $now, SymfonyStyle $io): bool
    {
        $today = $this->participationRepo->findToday($user, $now);
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
        $created = $this->send(
            $user,
            NotificationType::EventDay,
            $title,
            $body,
            $url,
            'day:' . $first->getDate()->format('Y-m-d'),
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
        if (!$this->dryRun) {
            $this->participationRepo->updateStaleUpcoming($user);
        }

        $reminders = $this->participationRepo->findPendingReminders($user);
        if ($reminders === []) {
            return false;
        }

        $count = count($reminders);
        $first = $reminders[0]->getEvent();

        $created = $this->send(
            $user,
            NotificationType::EventCompletion,
            $count === 1 ? 'C\'était comment, ' . $this->eventName($first) . ' ?' : 'Raconte tes derniers événements',
            $count === 1
                ? 'Tu n\'as pas encore rempli ta fiche pour ' . $this->eventName($first) . '.'
                : sprintf('%d événements attendent ta note et tes commentaires.', $count),
            $count === 1 ? '/event/' . $first->getId() . '/complete' : '/',
            'completion:' . $today,
        );

        if ($created) {
            $io->writeln(sprintf('  ⭐ %s (%d fiche(s))', $user->getUsername(), $count));
            if (!$this->dryRun) {
                $this->importSetlists($reminders, $io);
            }
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

        $created = $this->send(
            $user,
            NotificationType::EventAnniversary,
            $title,
            $body,
            '/event/' . $first->getId(),
            // Une seule fois par jour : le cron tourne chaque minute
            'anniversary:' . $now->format('Y-m-d'),
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
