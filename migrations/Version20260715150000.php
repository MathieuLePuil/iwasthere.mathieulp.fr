<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le Rewind débloqué par utilisateur : l'année du bilan et la date de
 * publication, d'où découle la fenêtre de visibilité d'un mois. Rien n'est
 * stocké pour l'expiration — elle se déduit de rewind_unlocked_at, ce qui
 * évite un état à maintenir à jour.
 */
final class Version20260715150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add rewind_year and rewind_unlocked_at to user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD rewind_year INT DEFAULT NULL, ADD rewind_unlocked_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP rewind_year, DROP rewind_unlocked_at');
    }
}
