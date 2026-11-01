<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260705100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop unused city column from venue';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE venue DROP COLUMN city');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE venue ADD COLUMN city VARCHAR(100) NOT NULL DEFAULT ''");
    }
}
