<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Entity\WatchNotification;
use App\Message\SendWatchAlert;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationType;
use App\Repository\WatchNotificationRepository;
use App\Ticketmaster\AlertComposer;
use App\Ticketmaster\ParisTime;
use App\Ticketmaster\WatchNotificationStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * L'envoi des alertes billetterie, à la minute (lot 3) :
 *
 *   * * * * *  php bin/console app:notifications:dispatch --env=prod
 *
 * Prend les échéances dont l'heure est atteinte et les pousse dans Messenger.
 * Indépendant de la synchronisation horaire : la date d'ouverture est connue
 * d'avance, l'échéance est planifiée, la granularité à la minute suffit à
 * faire tomber la H-1 juste.
 *
 * Regroupement : les échéances du même type pour le même utilisateur qui
 * tombent dans les deux minutes partent dans un seul push (beaucoup
 * d'ouvertures à 10:00). Plafond : dix pushes par utilisateur et par jour ;
 * au-delà, un résumé unique et les échéances sont marquées « capped ».
 *
 * Mode journalisation seule (--log-only ou TICKETMASTER_ALERTS_LOG_ONLY=1) :
 * rien ne part, l'échéance est consignée à l'heure où elle serait partie.
 */
#[AsCommand(
    name: 'app:notifications:dispatch',
    description: 'Envoie les alertes billetterie dont l\'heure est atteinte (à lancer chaque minute)',
)]
class DispatchWatchNotificationsCommand extends Command
{
    public const GROUP_WINDOW_SECONDS = 120;
    public const DAILY_CAP = 10;

    public function __construct(
        private readonly WatchNotificationRepository $notifications,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly NotificationDispatcher $notifier,
        private readonly LoggerInterface $logger,
        #[Autowire('%ticketmaster_alerts_log_only%')]
        private readonly bool $logOnlyByDefault,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('log-only', null, InputOption::VALUE_NONE, 'Consigner sans envoyer (vérification du calage des échéances)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Montrer ce qui partirait, sans rien écrire');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $logOnly = $this->logOnlyByDefault || (bool) $input->getOption('log-only');
        $dryRun = (bool) $input->getOption('dry-run');

        $due = $this->notifications->findDue($now);
        if ($due === []) {
            $io->writeln('Rien à envoyer.', OutputInterface::VERBOSITY_VERBOSE);

            return Command::SUCCESS;
        }

        $groups = $this->group($due, $now);
        $batches = 0;

        foreach ($groups as $items) {
            $first = $items[0];
            $user = $first->getWatch()->getUser();

            // Sans URL actionnable, pas d'envoi
            $items = array_values(array_filter($items, function (WatchNotification $n) use ($now, $dryRun, $io): bool {
                $event = $n->getWatch()->getEvent();
                if ($event->getUrl() !== null || $n->getSaleWindow()?->getUrl() !== null) {
                    return true;
                }
                $io->writeln(sprintf('  ⤫ %s : %s sans URL, ignorée', $n->getType()->value, $event->getName()));
                if (!$dryRun) {
                    $n->close(WatchNotificationStatus::Skipped, $now);
                }

                return false;
            }));
            if ($items === []) {
                continue;
            }

            $label = sprintf(
                '%s %s → @%s : %s',
                $first->getType()->value,
                ParisTime::toParis($first->getScheduledFor())->format('d/m H:i'),
                $user->getUsername(),
                implode(', ', array_map(static fn (WatchNotification $n) => $n->getWatch()->getEvent()->getName(), $items)),
            );

            if ($dryRun) {
                $io->writeln('  → ' . $label);
                $batches++;
                continue;
            }

            $batch = Uuid::v7();
            foreach ($items as $n) {
                $n->markQueued($batch, $now);
            }

            // Flush à chaque lot : le compteur du plafond doit voir les lots précédents de ce passage
            if ($this->notifications->countBatchesSince($user, ParisTime::startOfDay($now)) >= self::DAILY_CAP) {
                foreach ($items as $n) {
                    $n->close(WatchNotificationStatus::Capped, $now);
                }
                $this->em->flush();
                $this->sendCapSummary($user, $now, $logOnly);
                $io->writeln('  ⛔ plafond : ' . $label);
                continue;
            }

            if ($logOnly) {
                foreach ($items as $n) {
                    $n->close(WatchNotificationStatus::Logged, $now);
                }
                $this->logger->info('[log-only] alerte billetterie', ['batch' => (string) $batch, 'label' => $label]);
                $io->writeln('  📝 ' . $label);
            }
            $this->em->flush();
            if (!$logOnly) {
                // Après le flush : le handler doit trouver le lot en base
                $this->bus->dispatch(new SendWatchAlert((string) $batch));
                $io->writeln('  📨 ' . $label);
            }
            $batches++;
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->success(sprintf('%d envoi(s) %s.', $batches, $dryRun ? 'simulé(s)' : ($logOnly ? 'journalisé(s)' : 'poussé(s) dans Messenger')));

        return Command::SUCCESS;
    }

    /**
     * Regroupe par utilisateur, type et canal — et rattache les échéances du
     * même groupe qui tombent dans les deux minutes qui suivent, pour ne pas
     * envoyer deux pushes à une minute d'écart.
     *
     * @param list<WatchNotification> $due
     *
     * @return list<list<WatchNotification>>
     */
    private function group(array $due, \DateTimeImmutable $now): array
    {
        $groups = [];
        foreach ($due as $n) {
            $watch = $n->getWatch();
            $key = $watch->getUser()->getId() . '|' . $n->getType()->value . '|' . $watch->getChannel()->value;
            $groups[$key][] = $n;
        }

        foreach ($groups as $key => $items) {
            $latest = max(array_map(static fn (WatchNotification $n) => $n->getScheduledFor()->getTimestamp(), $items));
            $until = (new \DateTimeImmutable('@' . ($latest + self::GROUP_WINDOW_SECONDS)))->setTimezone(new \DateTimeZone('UTC'));
            $first = $items[0];
            $channel = $first->getWatch()->getChannel();
            foreach ($this->notifications->findPendingSoon($first->getWatch()->getUser(), $first->getType(), $now, $until) as $soon) {
                if ($soon->getWatch()->getChannel() === $channel) {
                    $groups[$key][] = $soon;
                }
            }
        }

        return array_values($groups);
    }

    /** Un seul résumé par jour, quel que soit le nombre de lots plafonnés. */
    private function sendCapSummary(User $user, \DateTimeImmutable $now, bool $logOnly): void
    {
        $key = 'tm-cap:' . ParisTime::toParis($now)->format('Y-m-d');
        if ($logOnly) {
            $this->logger->info('[log-only] résumé plafond', ['user' => $user->getUsername(), 'key' => $key]);

            return;
        }

        $this->notifier->dispatch(
            $user,
            NotificationType::TicketOnsale,
            'Beaucoup d\'alertes aujourd\'hui',
            sprintf('Tu as reçu %d alertes billetterie aujourd\'hui, on s\'arrête là. Les suivantes sont dans tes veilles.', self::DAILY_CAP),
            AlertComposer::WATCH_LIST_URL,
            dedupeKey: $key,
        );
    }
}
