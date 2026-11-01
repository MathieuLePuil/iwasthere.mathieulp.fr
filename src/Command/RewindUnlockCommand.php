<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationType;
use App\Repository\UserRepository;
use App\Rewind\RewindService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Publie le Rewind. Sans argument, pour tout le monde ; avec un @pseudo, pour
 * une seule personne.
 *
 * Le déblocage est le moment où chacun est prévenu : la fenêtre d'un mois part
 * de maintenant, pas du 1er janvier. On saute ceux dont l'année est trop maigre
 * — les prévenir d'un Rewind vide serait pire que de ne rien envoyer.
 */
#[AsCommand(
    name: 'app:rewind:unlock',
    description: 'Unlock the Rewind for everyone (or one user) and notify them',
)]
class RewindUnlockCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly EntityManagerInterface $em,
        private readonly RewindService $rewind,
        private readonly NotificationDispatcher $notifier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('user', InputArgument::OPTIONAL, 'Limiter à un utilisateur (@pseudo)')
            ->addOption('year', 'y', InputOption::VALUE_REQUIRED, 'Année du bilan', (new \DateTimeImmutable())->format('Y'))
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Montrer qui serait débloqué, sans rien écrire');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $year = (int) $input->getOption('year');
        $dryRun = (bool) $input->getOption('dry-run');

        $targets = $this->resolveTargets($input->getArgument('user'), $io);
        if ($targets === null) {
            return Command::FAILURE;
        }

        $io->title(sprintf('Rewind %d%s', $year, $dryRun ? ' (simulation)' : ''));

        $unlocked = 0;
        $skipped = 0;
        foreach ($targets as $user) {
            $data = $this->rewind->build($user, $year);
            if ($data === null) {
                $io->writeln(sprintf('  <comment>—</comment> @%s : pas assez d\'événements en %d', $user->getUsername(), $year));
                $skipped++;
                continue;
            }

            $io->writeln(sprintf(
                '  <info>✓</info> @%s : %d événements, %d diapos',
                $user->getUsername(),
                $data['total'],
                count($data['slides']),
            ));

            if ($dryRun) {
                $unlocked++;
                continue;
            }

            $user->unlockRewind($year);
            $this->em->flush();

            $this->notifier->dispatch(
                $user,
                NotificationType::RewindAvailable,
                'Ton Rewind ' . $year . ' est prêt 🎁',
                $data['total'] . ' événements, une année à revoir. Disponible pendant un mois.',
                '/rewind',
                ['year' => $year],
                // Republier la même année ne renotifie pas : sans ça, relancer la
                // commande enverrait un doublon à ceux qui l'ont déjà reçu
                dedupeKey: 'rewind:' . $year,
            );
            $unlocked++;
        }

        $io->newLine();
        if ($dryRun) {
            $io->note(sprintf('%d débloqué(s), %d ignoré(s) — rien n\'a été écrit.', $unlocked, $skipped));

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            'Rewind %d débloqué pour %d utilisateur(s)%s. Visible jusqu\'au %s.',
            $year,
            $unlocked,
            $skipped > 0 ? sprintf(' (%d ignoré(s), année trop courte)', $skipped) : '',
            (new \DateTimeImmutable('+1 month'))->format('d/m/Y'),
        ));

        return Command::SUCCESS;
    }

    /**
     * @return User[]|null null si le pseudo demandé est introuvable
     */
    private function resolveTargets(?string $username, SymfonyStyle $io): ?array
    {
        if ($username === null) {
            return $this->userRepo->findAll();
        }

        $user = $this->userRepo->findOneBy(['username' => ltrim($username, '@')]);
        if (!$user) {
            $io->error(sprintf('Aucun utilisateur « %s ».', $username));

            return null;
        }

        return [$user];
    }
}
