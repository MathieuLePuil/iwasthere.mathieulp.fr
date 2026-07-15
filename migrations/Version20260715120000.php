<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remplace les booléens de notification par `notif_prefs`, une clé par type.
 *
 * Report des valeurs existantes : seul `notif_completion_enabled` était lu par
 * le code, c'est donc le seul qui reflète un choix réel — on le garde tel quel,
 * pour ne pas mettre en route un rappel quotidien chez quelqu'un qui l'avait
 * coupé. `notif_presence_enabled` et `notif_friend_request_enabled` n'étaient
 * lus nulle part : leur « faux » ne décrivait rien, ces utilisateurs recevaient
 * les push de toute façon. Les repasser à « activé » (clé absente) ne change donc
 * rien à ce qu'ils reçoivent déjà.
 *
 * Une clé absente vaut « activé » (User::wantsPush) : n'écrire que ce qui est
 * désactivé garde la colonne lisible et les types futurs activés par défaut.
 */
final class Version20260715120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace per-type notification booleans with a notif_prefs JSON map';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD notif_prefs JSON DEFAULT NULL');

        $this->addSql('UPDATE `user` SET notif_prefs = \'{"event_completion":false}\' WHERE notif_completion_enabled = 0');
        $this->addSql('UPDATE `user` SET notif_prefs = \'{}\' WHERE notif_completion_enabled = 1');

        $this->addSql('ALTER TABLE `user` MODIFY notif_prefs JSON NOT NULL');
        $this->addSql('ALTER TABLE `user` DROP notifications_enabled, DROP notif_completion_enabled, DROP notif_presence_enabled, DROP notif_friend_request_enabled');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD notifications_enabled TINYINT(1) DEFAULT 1 NOT NULL, ADD notif_completion_enabled TINYINT(1) DEFAULT 0 NOT NULL, ADD notif_presence_enabled TINYINT(1) DEFAULT 0 NOT NULL, ADD notif_friend_request_enabled TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('UPDATE `user` SET notif_completion_enabled = 1 WHERE JSON_EXTRACT(notif_prefs, \'$.event_completion\') IS NULL OR JSON_EXTRACT(notif_prefs, \'$.event_completion\') = TRUE');
        $this->addSql('ALTER TABLE `user` DROP notif_prefs');
    }
}
