<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\NotificationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:push:test',
    description: 'Send a test push notification to every stored subscription',
)]
class SendTestNotificationCommand extends Command
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('message', InputArgument::OPTIONAL, 'Notification body', 'Ceci est une notification de test.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $message = $input->getArgument('message');

        $result = $this->notificationService->sendNotification('IWasThere — Test', $message);

        $io->writeln(sprintf('Sent : %d — Failed : %d', $result['sent'] ?? 0, $result['failed'] ?? 0));

        return ($result['sent'] ?? 0) > 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
