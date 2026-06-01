<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\PushSubscriptionRepository;
use Doctrine\DBAL\Connection;
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
        private readonly Connection $db,
        private readonly PushSubscriptionRepository $subRepo,
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
        $found = ['app_push_ping' => null, 'app_push_subscribe' => null];
        foreach (array_keys($found) as $name) {
            $route = $routes->get($name);
            $found[$name] = $route ? sprintf('%s %s', implode('|', $route->getMethods() ?: ['ANY']), $route->getPath()) : 'MISSING';
        }
        foreach ($found as $name => $info) {
            $ok = $info !== 'MISSING';
            $io->writeln(sprintf(' %s  %s : %s', $ok ? '<info>OK</info>' : '<error>KO</error>', $name, $info));
        }
        if (in_array('MISSING', $found, true)) {
            $io->warning('Au moins une route manque. Exécute : bin/console cache:clear');
        }

        // 2. VAPID
        $io->section('Clés VAPID');
        $rows = [
            ['VAPID_PUBLIC_KEY', $this->mask($this->vapidPublicKey)],
            ['VAPID_PRIVATE_KEY', $this->mask($this->vapidPrivateKey)],
            ['VAPID_SUBJECT', $this->vapidSubject ?: '(vide)'],
        ];
        $io->table(['Variable', 'Valeur'], $rows);
        if (!$this->vapidPublicKey || !$this->vapidPrivateKey) {
            $io->error('Clés VAPID manquantes ! Génère-les avec : bin/console app:generate-vapid-keys');
        }

        // 3. Table
        $io->section('Table push_subscription');
        try {
            $schema = $this->db->createSchemaManager();
            if (!$schema->tablesExist(['push_subscription'])) {
                $io->error('La table push_subscription n\'existe pas. Exécute : bin/console doctrine:migrations:migrate');
                return Command::FAILURE;
            }
            $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM push_subscription');
            $io->writeln(sprintf(' Total des abonnements : <info>%d</info>', $total));
        } catch (\Throwable $e) {
            $io->error('Erreur DB : ' . $e->getMessage());
            return Command::FAILURE;
        }

        // 4. Détail par user
        $io->section('Abonnements par utilisateur');
        $rows = $this->db->fetchAllAssociative(
            'SELECT u.username, COUNT(ps.id) AS n
             FROM `user` u
             LEFT JOIN push_subscription ps ON ps.user_id = u.id
             GROUP BY u.id, u.username
             ORDER BY n DESC, u.username ASC'
        );
        $tbl = [];
        foreach ($rows as $r) {
            $tbl[] = ['@' . $r['username'], $r['n']];
        }
        if ($tbl) {
            $io->table(['Username', 'Abonnements'], $tbl);
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
