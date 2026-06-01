<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\PushSubscriptionRepository;
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
        private readonly PushSubscriptionRepository $subRepo,
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
            $io->error(sprintf('Utilisateur "%s" introuvable.', $username));
            $io->note('Liste des usernames possibles :');
            $allUsers = $this->userRepo->findAll();
            foreach ($allUsers as $u) {
                $io->writeln(' - @' . $u->getUsername() . ' (' . $u->getEmail() . ')');
            }
            return Command::FAILURE;
        }

        $io->section('Utilisateur trouvé');
        $io->writeln(sprintf(' - id       : %s', $user->getId()));
        $io->writeln(sprintf(' - username : @%s', $user->getUsername()));
        $io->writeln(sprintf(' - email    : %s', $user->getEmail()));

        $subs = $this->subRepo->findByUser($user);
        $io->section(sprintf('Abonnements push enregistrés (%d)', count($subs)));
        if (empty($subs)) {
            $io->warning('Aucun abonnement en base pour cet utilisateur.');
            $io->writeln('Vérifier :');
            $io->writeln(' 1. Que la table push_subscription existe : SHOW TABLES LIKE \'push_subscription\';');
            $io->writeln(' 2. Que le clic « Activer » dans la PWA n\'a pas levé d\'erreur (alerte JS).');
            $io->writeln(' 3. Que le user connecté dans la PWA est bien @' . $user->getUsername() . '.');
            return Command::FAILURE;
        }

        foreach ($subs as $i => $sub) {
            $host = parse_url($sub->getEndpoint(), PHP_URL_HOST) ?: '(inconnu)';
            $io->writeln(sprintf(' #%d  %s  (%s)', $i + 1, $host, $sub->getCreatedAt()->format('Y-m-d H:i')));
        }

        $io->section('Envoi…');
        $result = $this->push->sendToUser($user, 'IWasThere — Test', $message);
        $io->writeln(sprintf('Sent : %d — Failed : %d', $result['sent'], $result['failed']));

        return $result['sent'] > 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
