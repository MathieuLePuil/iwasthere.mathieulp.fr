<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\NotificationRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** À lancer chaque nuit (voir le README) : le fil ne garde que ce qui est récent ou pas encore lu. */
#[AsCommand(
    name: 'app:notifications:purge',
    description: 'Delete read notifications older than --days (default 90)',
)]
class PurgeNotificationsCommand extends Command
{
    public function __construct(private readonly NotificationRepository $notifications)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Âge minimal, en jours', '90');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = max(1, (int) $input->getOption('days'));

        $deleted = $this->notifications->purgeRead(new \DateTimeImmutable("-{$days} days"));
        $io->success(sprintf('%d notification(s) lue(s) de plus de %d jours supprimée(s).', $deleted, $days));

        return Command::SUCCESS;
    }
}
