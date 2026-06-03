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
    name: 'app:setlist:retry',
    description: 'Retry pending setlist imports from Setlist.fm',
)]
class RetrySetlistImportCommand extends Command
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

        $events = $this->eventRepo->findPendingSetlistImport();
        $io->info(sprintf('Found %d events pending setlist import', count($events)));

        $success = 0;
        foreach ($events as $event) {
            if ($this->setlistFmService->tryImportSetlist($event)) {
                $success++;
                $io->writeln(sprintf('  ✓ Imported: %s', $event->getArtistName()));
            }
        }

        $io->success(sprintf('%d/%d setlists imported', $success, count($events)));

        return Command::SUCCESS;
    }
}
