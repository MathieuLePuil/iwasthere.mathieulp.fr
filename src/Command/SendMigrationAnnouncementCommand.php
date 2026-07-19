<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

#[AsCommand(
    name: 'app:mail:migration-announcement',
    description: 'Send the "site moved to iwasthereapp.app" email to every user, or to a single user with --user',
)]
class SendMigrationAnnouncementCommand extends Command
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Target a single user by username (with or without @)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $users = [];
        if ($username = $input->getOption('user')) {
            $username = ltrim($username, '@');
            $user = $this->userRepository->findOneByUsername($username);
            if (!$user) {
                $io->error(sprintf('User "%s" not found.', $username));

                return Command::FAILURE;
            }
            $users = [$user];
        } else {
            $users = $this->userRepository->findAll();
        }

        $sent = 0;
        $failed = 0;
        foreach ($users as $user) {
            $html = $this->twig->render('emails/site_migration_announcement.html.twig', [
                'user' => $user,
            ]);

            $email = (new Email())
                ->from(new Address('noreply@iwasthereapp.app', 'IWasThere'))
                ->to($user->getEmail())
                ->subject('IWasThere change d\'adresse — mets à jour ton app')
                ->html($html);

            try {
                $this->mailer->send($email);
                $sent++;
                $io->writeln(sprintf('  ✓ %s <%s>', $user->getUsername(), $user->getEmail()));
            } catch (\Throwable $e) {
                $failed++;
                $io->writeln(sprintf('  ✗ %s <%s> — %s', $user->getUsername(), $user->getEmail(), $e->getMessage()));
            }

            // Ne sature pas le serveur SMTP local lors d'un envoi de masse
            usleep(250_000);
        }

        $io->success(sprintf('Sent : %d — Failed : %d', $sent, $failed));

        return $failed > 0 && $sent === 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
