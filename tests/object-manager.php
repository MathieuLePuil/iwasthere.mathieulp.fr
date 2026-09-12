<?php

declare(strict_types=1);

// Donne à phpstan-doctrine l'EntityManager réel : les résultats de requêtes sont
// typés d'après le mapping, et les DQL sont vérifiés à l'analyse.
use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');

$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'prod', (bool) ($_SERVER['APP_DEBUG'] ?? false));
$kernel->boot();

return $kernel->getContainer()->get('doctrine')->getManager();
