<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\PushService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:push:test',
    description: 'Send a test push notification to a user',
)]
class SendTestNotificationCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly PushService $push,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'Target username')
            ->addArgument('message', InputArgument::OPTIONAL, 'Notification body', 'Ceci est une notification de test.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $username = $input->getArgument('username');
        $message = $input->getArgument('message');

        $user = $this->userRepo->findOneBy(['username' => $username]);
        if (!$user) {
            $io->error(sprintf('User "%s" not found.', $username));
            return Command::FAILURE;
        }

        $result = $this->push->sendToUser($user, 'IWasThere — Test', $message);

        $io->writeln(sprintf('Sent : %d — Failed : %d', $result['sent'], $result['failed']));

        return $result['sent'] > 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
