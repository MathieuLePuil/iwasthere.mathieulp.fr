<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260715100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add feed_last_seen_at to user (seen/unseen boundary in the friends feed)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD feed_last_seen_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP feed_last_seen_at');
    }
}
