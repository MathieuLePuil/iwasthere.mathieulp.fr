<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260714100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add artist_image_url to event (artist picture fetched from Deezer)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ADD artist_image_url VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event DROP artist_image_url');
    }
}
