<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Les abonnements push reviennent en base.
 *
 * La table push_subscription existait déjà (créée puis abandonnée pour un
 * fichier var/subscriptions.json, sujet aux écritures concurrentes) ; l'entité
 * PushSubscription la reprend telle quelle. Reste à y verser le contenu du
 * fichier, s'il existe : seuls les abonnements complets et rattachés à un compte
 * encore présent sont repris. Le fichier est ensuite renommé, pas supprimé.
 */
final class Version20260912173000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Import var/subscriptions.json into push_subscription';
    }

    public function up(Schema $schema): void
    {
        $file = dirname(__DIR__) . '/var/subscriptions.json';
        if (!is_file($file)) {
            return;
        }

        $subs = json_decode((string) file_get_contents($file), true);
        if (!is_array($subs)) {
            return;
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        foreach ($subs as $sub) {
            $endpoint = $sub['endpoint'] ?? null;
            $p256dh = $sub['keys']['p256dh'] ?? null;
            $auth = $sub['keys']['auth'] ?? null;
            $userId = $sub['userId'] ?? null;
            if (!is_string($endpoint) || !is_string($p256dh) || !is_string($auth)
                || !is_string($userId) || !Uuid::isValid($userId) || strlen($endpoint) > 500) {
                continue;
            }
            $userBinary = Uuid::fromString($userId)->toBinary();
            if (!$this->connection->fetchOne('SELECT 1 FROM `user` WHERE id = ?', [$userBinary])) {
                continue;
            }
            $this->connection->executeStatement(
                'INSERT IGNORE INTO push_subscription (id, endpoint, p256dh, auth, created_at, user_id) VALUES (?, ?, ?, ?, ?, ?)',
                [Uuid::v7()->toBinary(), $endpoint, $p256dh, $auth, $now, $userBinary],
            );
        }

        @rename($file, $file . '.imported-' . date('Y-m-d'));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM push_subscription');
    }
}
