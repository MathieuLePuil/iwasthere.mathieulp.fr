<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Préférence de thème par utilisateur. Défaut 'dark' et non 'auto' : les comptes
 * existants ont toujours connu l'app en sombre, un 'auto' rétroactif basculerait
 * en clair tous ceux dont le téléphone est en clair, sans qu'ils l'aient demandé.
 */
final class Version20260715160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add theme preference to user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE `user` ADD theme VARCHAR(10) DEFAULT 'dark' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP theme');
    }
}
