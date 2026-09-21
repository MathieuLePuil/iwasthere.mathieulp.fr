<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le flux porte le nom d'artiste dans attractions[] (68 % des événements Music) :
 * on le garde pour une recherche « par artiste » qui ne confond pas Muse et les
 * musées. Colonnes vides jusqu'à la prochaine synchronisation (le hash de chaque
 * ligne change, tout est réécrit une fois).
 */
final class Version20260919150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'tm_event.artist_name and artists_normalized (from attractions[])';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tm_event ADD artist_name VARCHAR(255) DEFAULT NULL, ADD artists_normalized VARCHAR(1000) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tm_event DROP artist_name, DROP artists_normalized');
    }
}
