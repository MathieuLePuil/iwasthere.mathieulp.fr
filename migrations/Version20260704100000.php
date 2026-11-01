<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260704100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move event photo to event_participation so each participant has their own';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event_participation ADD COLUMN image_url VARCHAR(500) DEFAULT NULL');
        // Keep existing photos: copy the event photo to every participant of that event
        $this->addSql('UPDATE event_participation ep JOIN event e ON e.id = ep.event_id SET ep.image_url = e.image_url WHERE e.image_url IS NOT NULL');
        $this->addSql('ALTER TABLE event DROP COLUMN image_url');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ADD COLUMN image_url VARCHAR(500) DEFAULT NULL');
        $this->addSql('UPDATE event e SET e.image_url = (SELECT MAX(ep.image_url) FROM event_participation ep WHERE ep.event_id = e.id AND ep.image_url IS NOT NULL)');
        $this->addSql('ALTER TABLE event_participation DROP COLUMN image_url');
    }
}
