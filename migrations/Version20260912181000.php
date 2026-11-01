<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Index sur ce que findDuplicate filtre (type, date, lieu) et sur le créateur d'un événement. */
final class Version20260912181000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Indexes on event (type, date, venue_id) and (created_by_user_id)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_event_duplicate ON event (type, date, venue_id)');
        $this->addSql('CREATE INDEX idx_event_creator ON event (created_by_user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_event_duplicate ON event');
        $this->addSql('DROP INDEX idx_event_creator ON event');
    }
}
