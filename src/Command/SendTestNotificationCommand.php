<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\PushService;
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
        private readonly PushService $push,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('message', InputArgument::OPTIONAL, 'Notification body', 'Ceci est une notification de test.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $message = $input->getArgument('message');

        $file = $this->projectDir . '/var/subscriptions.json';
        $count = file_exists($file)
            ? count(json_decode((string) @file_get_contents($file), true) ?? [])
            : 0;

        $io->writeln(sprintf('Fichier : %s', $file));
        $io->writeln(sprintf('Abonnements en base de fichier : <info>%d</info>', $count));

        if ($count === 0) {
            $io->warning('Aucun abonnement à notifier. Active d\'abord les notifs depuis la PWA.');
            return Command::FAILURE;
        }

        $result = $this->push->sendToAll('IWasThere — Test', $message);
        $io->writeln(sprintf('Sent : %d — Failed : %d', $result['sent'], $result['failed']));

        return $result['sent'] > 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
