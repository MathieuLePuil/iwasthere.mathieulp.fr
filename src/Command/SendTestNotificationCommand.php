<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Notification;
use App\Repository\PushSubscriptionRepository;
use App\Repository\UserRepository;
use App\Service\WebPushService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:notify:test',
    description: 'Send a test notification to a user (in-app + web push)',
)]
class SendTestNotificationCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly EntityManagerInterface $em,
        private readonly WebPushService $push,
        private readonly PushSubscriptionRepository $subRepo,
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

        $user = $this->userRepo->findOneByUsername($username);
        if (!$user) {
            $io->error(sprintf('User "%s" not found.', $username));
            return Command::FAILURE;
        }

        // In-app notification
        $notif = new Notification();
        $notif->setRecipient($user)
            ->setType('test')
            ->setTitle('Notification de test')
            ->setBody($message);

        $this->em->persist($notif);
        $this->em->flush();
        $io->text('✓ In-app notification created');

        // Check push subscriptions
        $subs = $this->subRepo->findByUser($user);
        $io->text(sprintf('Push subscriptions found: %d', count($subs)));

        if (empty($subs)) {
            $io->warning('No push subscriptions for this user. The user must click "Activer" in the app first.');
        }

        // Web push notification
        $this->push->sendToUser($user, 'Notification de test', $message);
        $io->text('✓ Web push dispatched (check logs if not received)');

        $io->success(sprintf('Done for @%s', $username));
        return Command::SUCCESS;
    }
}
