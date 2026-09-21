<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Alertes « nouvelle date d'un artiste » (module Ticketmaster) :
 *
 *  - artist_watch            : la wishlist — un utilisateur suit un artiste par son nom normalisé,
 *                              rapproché de tm_event.artists_normalized à chaque synchronisation
 *  - user.alert_seen_artists : opt-in pour être prévenu aussi des artistes déjà vus (journal)
 */
final class Version20260921100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Artist wishlist (artist_watch) and user.alert_seen_artists';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE artist_watch (id BINARY(16) NOT NULL, artist_name VARCHAR(255) NOT NULL, artist_normalized VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, user_id BINARY(16) NOT NULL, INDEX IDX_30077A75A76ED395 (user_id), INDEX idx_artist_watch_artist (artist_normalized), UNIQUE INDEX uq_artist_watch_user_artist (user_id, artist_normalized), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE artist_watch ADD CONSTRAINT FK_30077A75A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `user` ADD alert_seen_artists TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE artist_watch');
        $this->addSql('ALTER TABLE `user` DROP alert_seen_artists');
    }
}
