<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Notification;
use App\Entity\User;
use App\Notification\NotificationType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Retire le Rewind. Surtout pour les tests : rejouer le déblocage après coup.
 *
 * Retire aussi la notification correspondante par défaut — sinon son verrou
 * anti-doublon survivrait au verrouillage et un second `unlock` n'enverrait
 * plus rien, ce qui rend la boucle de test trompeuse.
 */
#[AsCommand(
    name: 'app:rewind:lock',
    description: 'Hide the Rewind again (mainly for testing)',
)]
class RewindLockCommand extends Command
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
            ->addArgument('user', InputArgument::OPTIONAL, 'Limiter à un utilisateur (@pseudo)')
            ->addOption('keep-notifications', null, InputOption::VALUE_NONE, 'Garder les notifications de Rewind');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $username = $input->getArgument('user');

        if ($username !== null) {
            $user = $this->userRepo->findOneBy(['username' => ltrim($username, '@')]);
            if (!$user) {
                $io->error(sprintf('Aucun utilisateur « %s ».', $username));

                return Command::FAILURE;
            }
            $targets = [$user];
        } else {
            $targets = $this->userRepo->findAll();
        }

        $locked = 0;
        $notifs = 0;
        foreach ($targets as $user) {
            if ($user->getRewindYear() !== null) {
                $io->writeln(sprintf('  <info>✓</info> @%s : Rewind %d retiré', $user->getUsername(), $user->getRewindYear()));
                $user->lockRewind();
                $locked++;
            }

            if (!$input->getOption('keep-notifications')) {
                $notifs += $this->removeNotifications($user);
            }
        }
        $this->em->flush();

        $io->success(sprintf(
            '%d Rewind retiré(s), %d notification(s) supprimée(s).',
            $locked,
            $notifs,
        ));

        return Command::SUCCESS;
    }

    private function removeNotifications(User $user): int
    {
        $notifs = $this->em->getRepository(Notification::class)->findBy([
            'recipient' => $user,
            'type' => NotificationType::RewindAvailable->value,
        ]);

        foreach ($notifs as $n) {
            $this->em->remove($n);
        }

        return count($notifs);
    }
}
