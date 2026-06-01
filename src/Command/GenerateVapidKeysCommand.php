<?php

declare(strict_types=1);

namespace App\Command;

use Minishlink\WebPush\VAPID;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:generate-vapid-keys',
    description: 'Generate VAPID keys for Web Push notifications',
)]
class GenerateVapidKeysCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $keys = VAPID::createVapidKeys();

        $io->success('VAPID keys generated. Copy them into your .env.local :');
        $io->writeln('VAPID_PUBLIC_KEY=' . $keys['publicKey']);
        $io->writeln('VAPID_PRIVATE_KEY=' . $keys['privateKey']);
        $io->writeln('VAPID_SUBJECT=mailto:contact@example.com');

        return Command::SUCCESS;
    }
}
