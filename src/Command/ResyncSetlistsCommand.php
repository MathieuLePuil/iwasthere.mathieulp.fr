<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\EventRepository;
use App\Service\SetlistFmService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:setlist:resync',
    description: 'Re-import all already-imported setlists to update tour name, tape, feat, cover info',
)]
class ResyncSetlistsCommand extends Command
{
    public function __construct(
        private readonly EventRepository $eventRepo,
        private readonly SetlistFmService $setlistFmService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $events = $this->eventRepo->findImportedSetlists();
        $io->info(sprintf('Found %d setlist.fm events to resync', count($events)));

        $success = 0;
        $failed = 0;
        foreach ($events as $event) {
            $io->write(sprintf('  %s (%s)… ', $event->getArtistName(), $event->getDate()->format('d/m/Y')));
            if ($this->setlistFmService->forceReimportSetlist($event)) {
                $io->writeln('<info>✓</info>');
                $success++;
            } else {
                $io->writeln('<comment>✗ not found</comment>');
                $failed++;
            }
            // Respect API rate limit (2 req/s on free plan)
            usleep(600_000);
        }

        $io->success(sprintf('%d resynced, %d not found on setlist.fm', $success, $failed));

        return Command::SUCCESS;
    }
}
