<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Notification;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:notify:test',
    description: 'Send a test notification to a user',
)]
class SendTestNotificationCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly EntityManagerInterface $em,
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

        $user = $this->userRepo->findOneByUsername($username);
        if (!$user) {
            $io->error(sprintf('User "%s" not found.', $username));
            return Command::FAILURE;
        }

        $notif = new Notification();
        $notif->setRecipient($user)
            ->setType('test')
            ->setTitle('Notification de test')
            ->setBody($input->getArgument('message'));

        $this->em->persist($notif);
        $this->em->flush();

        $io->success(sprintf('Test notification sent to @%s', $username));
        return Command::SUCCESS;
    }
}
