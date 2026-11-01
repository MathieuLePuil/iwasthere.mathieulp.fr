<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le jeton du profil public partageable.
 *
 * Nullable et sans valeur par défaut : c'est un opt-in, les comptes existants
 * restent donc fermés — null vaut « pas de profil public ». L'unicité est ce qui
 * garantit qu'un jeton ne résout que vers un seul compte.
 */
final class Version20260716110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add share_token to user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD share_token VARCHAR(32) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649D6594DD6 ON `user` (share_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_8D93D649D6594DD6 ON `user`');
        $this->addSql('ALTER TABLE `user` DROP share_token');
    }
}
