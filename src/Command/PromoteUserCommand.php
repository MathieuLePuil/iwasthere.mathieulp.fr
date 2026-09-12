<?php

declare(strict_types=1);

namespace App\Command;

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
 * Donne (ou retire) le rôle super-admin à un compte existant — le seul moyen
 * de créer le premier administrateur, l'interface admin étant derrière ce rôle.
 */
#[AsCommand(
    name: 'app:user:promote',
    description: 'Give (or take back with --demote) the super-admin role to an existing account',
)]
class PromoteUserCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('user', InputArgument::REQUIRED, 'Email ou @pseudo du compte')
            ->addOption('demote', null, InputOption::VALUE_NONE, 'Retirer le rôle au lieu de le donner');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $ref = (string) $input->getArgument('user');

        $user = str_contains($ref, '@') && !str_starts_with($ref, '@')
            ? $this->users->findOneByEmail(mb_strtolower($ref))
            : $this->users->findOneByUsername(ltrim($ref, '@'));

        if ($user === null) {
            $io->error(sprintf('Aucun compte pour « %s ».', $ref));

            return Command::FAILURE;
        }

        $role = $input->getOption('demote') ? 'user' : 'superAdmin';
        $user->setRole($role);
        $this->em->flush();

        $io->success(sprintf('@%s est maintenant « %s ».', $user->getUsername(), $role));

        return Command::SUCCESS;
    }
}
