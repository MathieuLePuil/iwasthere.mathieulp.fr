<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\RouterInterface;

#[AsCommand(
    name: 'app:push:diagnose',
    description: 'Diagnose the push notification configuration',
)]
class PushDiagnoseCommand extends Command
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly string $projectDir,
        private readonly string $vapidPublicKey,
        private readonly string $vapidPrivateKey,
        private readonly string $vapidSubject,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Push notification diagnosis');

        // 1. Routes
        $io->section('Routes');
        $routes = $this->router->getRouteCollection();
        foreach (['app_push_ping', 'app_push_subscribe'] as $name) {
            $route = $routes->get($name);
            $info = $route
                ? sprintf('%s %s', implode('|', $route->getMethods() ?: ['ANY']), $route->getPath())
                : '<error>MISSING</error>';
            $io->writeln(sprintf(' %s : %s', $name, $info));
        }

        // 2. VAPID
        $io->section('Clés VAPID');
        $io->table(
            ['Variable', 'Valeur'],
            [
                ['VAPID_PUBLIC_KEY', $this->mask($this->vapidPublicKey)],
                ['VAPID_PRIVATE_KEY', $this->mask($this->vapidPrivateKey)],
                ['VAPID_SUBJECT', $this->vapidSubject ?: '(vide)'],
            ],
        );
        if (!$this->vapidPublicKey || !$this->vapidPrivateKey) {
            $io->error('Clés VAPID manquantes. Lance : bin/console app:generate-vapid-keys');
        }

        // 3. Fichier des souscriptions
        $io->section('Souscriptions');
        $file = $this->projectDir . '/var/subscriptions.json';
        $dir = dirname($file);

        $io->writeln(sprintf(' Fichier      : %s', $file));
        $io->writeln(sprintf(' Dossier      : %s (existe=%s, writable=%s)',
            $dir,
            is_dir($dir) ? 'oui' : '<error>NON</error>',
            is_writable($dir) ? 'oui' : '<error>NON</error>',
        ));
        $io->writeln(sprintf(' Fichier      : existe=%s, writable=%s',
            file_exists($file) ? 'oui' : 'non',
            file_exists($file) ? (is_writable($file) ? 'oui' : '<error>NON</error>') : 'n/a',
        ));

        $subs = [];
        if (file_exists($file)) {
            $subs = json_decode((string) @file_get_contents($file), true) ?? [];
        }
        $io->writeln(sprintf(' Souscriptions: <info>%d</info>', count($subs)));
        foreach ($subs as $i => $sub) {
            $host = parse_url((string)($sub['endpoint'] ?? ''), PHP_URL_HOST) ?: '(invalide)';
            $io->writeln(sprintf('  #%d %s', $i + 1, $host));
        }

        // 4. Log
        $io->section('Log debug push');
        $log = $this->projectDir . '/var/push.log';
        if (file_exists($log)) {
            $io->writeln(' ' . $log);
            $lines = @file($log) ?: [];
            $tail = array_slice($lines, -8);
            foreach ($tail as $line) {
                $io->writeln('  ' . rtrim($line));
            }
        } else {
            $io->writeln(' Aucun log encore (var/push.log absent).');
        }

        $io->success('Diagnostic terminé.');
        return Command::SUCCESS;
    }

    private function mask(string $value): string
    {
        if (!$value) return '(vide)';
        if (strlen($value) <= 12) return $value;
        return substr($value, 0, 6) . '...' . substr($value, -4) . ' (' . strlen($value) . ' chars)';
    }
}
