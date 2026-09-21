<?php

declare(strict_types=1);

namespace App\Command;

use App\Notification\NotificationDispatcher;
use App\Notification\NotificationType;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Message libre de l'équipe (nouveauté, annonce…) à tout le monde, ou à un
 * seul utilisateur avec --user pour se relire avant l'envoi général.
 *
 * Passe par le dispatcher comme n'importe quelle notification : une entrée dans
 * le fil pour chacun (même sans abonnement push), et un push pour ceux qui n'ont
 * pas décoché « Nouveautés de l'app ». Les pushes partent via le worker Messenger.
 */
#[AsCommand(
    name: 'app:notifications:announce',
    description: 'Notify every user (feed + push) with a free title and body, or a single user with --user',
)]
class AnnounceCommand extends Command
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly UserRepository $users,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('title', InputArgument::REQUIRED, 'Titre (en-tête du push et de la carte du fil)')
            ->addArgument('body', InputArgument::REQUIRED, 'Contenu')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Cibler un seul utilisateur par pseudo (avec ou sans @)')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Page ouverte au clic (chemin relatif)', '/home')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compter les destinataires sans rien écrire ni envoyer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $title = trim((string) $input->getArgument('title'));
        $body = trim((string) $input->getArgument('body'));
        $url = (string) $input->getOption('url');
        $dryRun = (bool) $input->getOption('dry-run');

        if ($title === '' || $body === '') {
            $io->error('Le titre et le contenu ne peuvent pas être vides.');

            return Command::INVALID;
        }

        if ($username = $input->getOption('user')) {
            $user = $this->users->findOneByUsername(ltrim($username, '@'));
            if (!$user) {
                $io->error(sprintf('Utilisateur "%s" introuvable.', $username));

                return Command::FAILURE;
            }
            $recipients = [$user];
        } else {
            $recipients = $this->users->findAll();
        }

        $io->section('Aperçu');
        $io->writeln(sprintf('  <info>%s</info>', $title));
        $io->writeln(sprintf('  %s', $body));
        $io->writeln(sprintf('  → %s', $url));
        $io->newLine();

        $withPush = count(array_filter($recipients, static fn ($u) => $u->wantsPush(NotificationType::Announcement)));
        $io->writeln(sprintf('%d destinataire(s), dont %d avec le push « %s » activé.', count($recipients), $withPush, NotificationType::Announcement->label()));

        if ($dryRun) {
            $io->success('Simulation : rien n\'a été écrit.');

            return Command::SUCCESS;
        }

        // Un envoi général ne se rattrape pas : on confirme, sauf en --no-interaction
        if (count($recipients) > 1 && !$io->confirm(sprintf('Envoyer à ces %d utilisateurs ?', count($recipients)), false)) {
            $io->warning('Annulé.');

            return Command::SUCCESS;
        }

        $sent = 0;
        foreach ($recipients as $user) {
            $this->dispatcher->dispatch($user, NotificationType::Announcement, $title, $body, $url);
            $sent++;
            $io->writeln(sprintf('  ✓ @%s', $user->getUsername()), OutputInterface::VERBOSITY_VERBOSE);
        }

        $io->success(sprintf('%d notification(s) inscrite(s) dans le fil — les pushes partent par le worker Messenger.', $sent));

        return Command::SUCCESS;
    }
}
