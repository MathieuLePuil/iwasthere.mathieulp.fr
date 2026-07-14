<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\NotificationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:push:test',
    description: 'Send a test push notification to every stored subscription, or to a single user with --user',
)]
class SendTestNotificationCommand extends Command
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('message', InputArgument::OPTIONAL, 'Notification body', 'Ceci est une notification de test.')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Target a single user by username (with or without @)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $message = $input->getArgument('message');

        $userId = null;
        if ($username = $input->getOption('user')) {
            $username = ltrim($username, '@');
            $user = $this->userRepository->findOneBy(['username' => $username]);
            if (!$user) {
                $io->error(sprintf('User "%s" not found.', $username));

                return Command::FAILURE;
            }
            $userId = (string) $user->getId();
        }

        $result = $this->notificationService->sendNotification('IWasThere — Test', $message, $userId);

        $io->writeln(sprintf('Sent : %d — Failed : %d', $result['sent'] ?? 0, $result['failed'] ?? 0));
        if (isset($result['message'])) {
            $io->writeln($result['message']);
        }

        return ($result['sent'] ?? 0) > 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
