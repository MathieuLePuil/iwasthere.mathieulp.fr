<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260602100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove start_time column from event table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event DROP COLUMN start_time');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE event ADD COLUMN start_time VARCHAR(5) DEFAULT NULL");
    }
}
