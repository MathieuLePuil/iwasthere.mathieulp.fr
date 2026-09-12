<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Colonnes que rien n'écrivait ni ne lisait : event.intermediate_scores,
 * event_participation.photos (la photo vit dans image_url), friend.display_name.
 */
final class Version20260912182000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop unused columns event.intermediate_scores, event_participation.photos, friend.display_name';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event DROP intermediate_scores');
        $this->addSql('ALTER TABLE event_participation DROP photos');
        $this->addSql('ALTER TABLE friend DROP display_name');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ADD intermediate_scores JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE event_participation ADD photos JSON NOT NULL DEFAULT \'[]\'');
        $this->addSql('ALTER TABLE friend ADD display_name VARCHAR(100) DEFAULT NULL');
    }
}
