<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Event;
use App\Service\DeezerArtistService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:artist-images:backfill',
    description: 'Fetch missing artist pictures from Deezer for music events',
)]
class BackfillArtistImagesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DeezerArtistService $deezer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $events = $this->em->getRepository(Event::class)->createQueryBuilder('e')
            ->where('e.category = :cat')
            ->andWhere('e.artistName IS NOT NULL')
            ->andWhere('e.artistImageUrl IS NULL')
            ->setParameter('cat', 'music')
            ->getQuery()
            ->getResult();

        $io->info(sprintf('Found %d music events without artist image', count($events)));

        $success = 0;
        foreach ($events as $event) {
            if ($this->deezer->applyToEvent($event)) {
                $success++;
                $io->writeln(sprintf('  ✓ %s', $event->getArtistName()));
            } else {
                $io->writeln(sprintf('  ✗ %s (not found)', $event->getArtistName()));
            }
            // Stay well under Deezer's quota (50 req / 5 s)
            usleep(250_000);
        }

        $this->em->flush();
        $io->success(sprintf('%d/%d artist images fetched', $success, count($events)));

        return Command::SUCCESS;
    }
}
