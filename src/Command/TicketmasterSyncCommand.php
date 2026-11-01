<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\EventWatchRepository;
use App\Repository\TmEventRepository;
use App\Repository\WatchNotificationRepository;
use App\Ticketmaster\ArtistAnnouncer;
use App\Ticketmaster\CatalogWriter;
use App\Ticketmaster\ChangeDetector;
use App\Ticketmaster\EventProjector;
use App\Ticketmaster\FeedDownloader;
use App\Ticketmaster\FeedException;
use App\Ticketmaster\FeedReader;
use App\Ticketmaster\SearchCriteria;
use App\Ticketmaster\WatchScheduler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * La synchronisation horaire du catalogue Ticketmaster (lot 1).
 *
 *   17 * * * *  php bin/console app:ticketmaster:sync --env=prod
 *
 * Minute décalée : à l'heure pile, le fichier S3 peut être en cours de
 * régénération. Déroulé :
 *
 *   1. verrou — abandon immédiat si un passage est en cours ;
 *   2. espace disque ;
 *   3. téléchargement du .gz en temporaire ;
 *   4. lecture en flux, projection, filtrage sur les segments suivis (Music au
 *      minimum) — tout est gardé en mémoire jusqu'à la fin du fichier : un flux
 *      tronqué ou illisible lève avant la première écriture ;
 *   5. upsert par lots (CatalogWriter, une transaction) ;
 *   6. changements sur les événements suivis, puis replanification des échéances ;
 *      annonces « nouvelle date d'un artiste » sur ce qui vient d'entrer au
 *      catalogue (wishlist, artistes déjà vus) ;
 *   7. nettoyage du temporaire, même en cas d'erreur ;
 *   8. purge (événements absents depuis 7 jours et non suivis, échéances
 *      envoyées depuis 90 jours).
 *
 * Cible : moins de 10 minutes. Au-delà de 45, une erreur est journalisée
 * (prod.log) — c'est l'alerte d'exploitation.
 */
#[AsCommand(
    name: 'app:ticketmaster:sync',
    description: 'Synchronise le catalogue Ticketmaster France (flux Discovery Feed) et replanifie les alertes',
)]
class TicketmasterSyncCommand extends Command
{
    private const PURGE_UNSEEN_DAYS = 7;
    private const PURGE_SENT_DAYS = 90;
    private const WARN_AFTER_SECONDS = 10 * 60;
    private const ALERT_AFTER_SECONDS = 45 * 60;
    /**
     * Au-delà, les « nouveautés » ne sont pas des annonces : un premier passage,
     * un segment ajouté ou un catalogue reconstruit entrent des milliers
     * d'événements d'un coup — on ne noie personne sous des notifications.
     */
    private const MAX_NEW_FOR_ANNOUNCEMENTS = 2000;

    public function __construct(
        private readonly FeedDownloader $downloader,
        private readonly FeedReader $reader,
        private readonly CatalogWriter $writer,
        private readonly WatchScheduler $scheduler,
        private readonly ArtistAnnouncer $announcer,
        private readonly EventWatchRepository $watches,
        private readonly TmEventRepository $events,
        private readonly WatchNotificationRepository $notifications,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%ticketmaster_segments%')]
        private readonly string $defaultSegments,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'Lire ce .gz local au lieu de télécharger le flux')
            ->addOption('segments', 's', InputOption::VALUE_REQUIRED, 'Segments à garder, séparés par des virgules (défaut : TICKETMASTER_SEGMENTS + segments suivis ; « all » pour tout)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Lire et compter, sans rien écrire')
            ->addOption('keep', null, InputOption::VALUE_NONE, 'Conserver le fichier téléchargé dans var/tmp/ticketmaster');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $started = microtime(true);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $dryRun = (bool) $input->getOption('dry-run');

        $lock = $this->acquireLock();
        if ($lock === null) {
            $io->warning('Une synchronisation est déjà en cours — abandon.');

            return Command::SUCCESS;
        }

        $tmpDir = $this->projectDir . '/var/tmp/ticketmaster';
        $localFile = $input->getOption('file');
        $gzPath = is_string($localFile) ? $localFile : $tmpDir . '/fr-' . $now->format('Ymd-His') . '.json.gz';
        $downloaded = false;

        try {
            $segments = $this->segments($input->getOption('segments'));
            $io->text(sprintf('Segments : %s', $segments === null ? 'tous' : implode(', ', $segments)));

            // 2-3. Téléchargement
            if (!is_string($localFile)) {
                if (!is_dir($tmpDir) && !mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
                    throw new FeedException(sprintf('Impossible de créer %s.', $tmpDir));
                }
                FeedDownloader::assertDiskSpace($tmpDir);
                $bytes = $this->downloader->download($gzPath);
                $downloaded = true;
                $io->text(sprintf('Téléchargé : %s (%.1f Mo, %.0f s)', basename($gzPath), $bytes / 1048576, microtime(true) - $started));
            } elseif (!is_readable($gzPath)) {
                throw new FeedException(sprintf('Fichier illisible : %s', $gzPath));
            }

            // 4. Lecture complète avant toute écriture
            $rows = [];
            $total = 0;
            foreach ($this->reader->events($gzPath) as $raw) {
                $total++;
                $row = EventProjector::project($raw, $segments);
                if ($row !== null) {
                    $rows[$row['event_id']] = $row;
                }
            }
            $io->text(sprintf('Lu : %d événements, %d retenus (%.0f s)', $total, count($rows), microtime(true) - $started));

            if ($rows === []) {
                throw new FeedException('Aucun événement retenu : flux vide ou segments absents — rien n\'est écrit.');
            }

            if ($dryRun) {
                $io->success('Simulation : rien n\'a été écrit.');

                return Command::SUCCESS;
            }

            // 5-6. Écriture, changements, replanification
            $snapshot = $this->watches->snapshotWatchedEvents();
            $changes = ChangeDetector::detect($snapshot, $rows);

            $stats = $this->writer->write($rows, $now);
            $io->text(sprintf('Catalogue : %d nouveaux, %d modifiés, %d inchangés', $stats['inserted'], $stats['updated'], $stats['unchanged']));

            $notified = $this->scheduler->applyChanges($changes, $now);
            $plan = $this->scheduler->reconcileAll($now);
            $io->text(sprintf(
                'Veilles : %d changement(s) notifié(s), %d veille(s) replanifiée(s), %d échéance(s) créée(s), %d annulée(s)',
                $notified,
                $plan['watches'],
                $plan['created'],
                $plan['cancelled'],
            ));

            $initialLoad = $stats['updated'] + $stats['unchanged'] === 0;
            if ($initialLoad || count($stats['new_ids']) > self::MAX_NEW_FOR_ANNOUNCEMENTS) {
                $io->text(sprintf('Artistes : %d nouveauté(s), pas d\'annonce (%s)', count($stats['new_ids']), $initialLoad ? 'premier passage' : 'volume anormal'));
            } else {
                $announced = $this->announcer->announce(array_intersect_key($rows, array_flip($stats['new_ids'])), $now);
                $io->text(sprintf('Artistes : %d nouveauté(s), %d annonce(s) envoyée(s)', count($stats['new_ids']), $announced));
            }

            // 8. Purge
            $purgedEvents = $this->events->purgeUnseen($now->modify(sprintf('-%d days', self::PURGE_UNSEEN_DAYS)));
            $purgedNotifs = $this->notifications->purgeSent($now->modify(sprintf('-%d days', self::PURGE_SENT_DAYS)));
            $io->text(sprintf('Purge : %d événement(s), %d échéance(s)', $purgedEvents, $purgedNotifs));
        } catch (FeedException|\JsonMachine\Exception\JsonMachineException $e) {
            $this->logger->error('Synchronisation Ticketmaster abandonnée', ['exception' => $e]);
            $io->error($e->getMessage());

            return Command::FAILURE;
        } finally {
            // 7. Nettoyage — quoi qu'il soit arrivé
            if ($downloaded && !$input->getOption('keep')) {
                @unlink($gzPath);
            }
            flock($lock, LOCK_UN);
            fclose($lock);

            $elapsed = (int) (microtime(true) - $started);
            if ($elapsed > self::ALERT_AFTER_SECONDS) {
                $this->logger->error('Synchronisation Ticketmaster anormalement longue', ['seconds' => $elapsed]);
            } elseif ($elapsed > self::WARN_AFTER_SECONDS) {
                $this->logger->warning('Synchronisation Ticketmaster au-delà de la cible', ['seconds' => $elapsed]);
            }
        }

        $io->success(sprintf('Synchronisation terminée en %d s.', (int) (microtime(true) - $started)));

        return Command::SUCCESS;
    }

    /**
     * Les segments à garder : ceux de la configuration (Music par défaut), plus
     * tout segment suivi par une veille active — ou ce que demande --segments.
     * Null = tout le catalogue.
     *
     * @return list<string>|null
     */
    private function segments(mixed $option): ?array
    {
        $configured = is_string($option) && $option !== '' ? $option : $this->defaultSegments;
        if (strtolower(trim($configured)) === 'all') {
            return null;
        }

        $segments = array_filter(array_map('trim', explode(',', $configured)));
        if (!is_string($option) || $option === '') {
            $segments = [...$segments, SearchCriteria::DEFAULT_SEGMENT, ...$this->watches->findFollowedSegments()];
        }

        return array_values(array_unique($segments));
    }

    /**
     * Verrou de fichier : deux passages ne se chevauchent pas.
     *
     * @return resource|null null si un autre passage tient le verrou
     */
    private function acquireLock()
    {
        $path = $this->projectDir . '/var/tm-sync.lock';
        $handle = fopen($path, 'c');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Impossible d\'ouvrir le verrou %s.', $path));
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }
}
