<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module « Alertes d'ouverture de billetterie » (Ticketmaster Discovery Feed).
 *
 *  - tm_event          : le catalogue France projeté (clé naturelle eventId, collation binaire : les ids
 *                        Ticketmaster sont sensibles à la casse), plein texte sur name_normalized
 *  - tm_sale_window    : les fenêtres de vente (générale aujourd'hui, préventes si le flux en expose un jour)
 *  - event_watch       : une veille = un utilisateur suit un événement
 *  - watch_notification: les échéances (J-1, H-1, annulation, report, ouverture déplacée),
 *                        uniques par (veille, fenêtre, type, heure) — jamais de doublon à la replanification
 */
final class Version20260919120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ticketmaster onsale alerts: tm_event, tm_sale_window, event_watch, watch_notification';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tm_event (event_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` NOT NULL, name VARCHAR(255) NOT NULL, name_normalized VARCHAR(255) NOT NULL, status VARCHAR(20) NOT NULL, event_start_utc DATETIME DEFAULT NULL, event_start_local_date DATE DEFAULT NULL, event_start_local_time VARCHAR(8) DEFAULT NULL, venue_name VARCHAR(255) DEFAULT NULL, venue_city VARCHAR(120) DEFAULT NULL, venue_timezone VARCHAR(64) DEFAULT NULL, segment VARCHAR(60) DEFAULT NULL, genre VARCHAR(60) DEFAULT NULL, url VARCHAR(500) DEFAULT NULL, source VARCHAR(40) DEFAULT NULL, first_seen_at DATETIME NOT NULL, last_seen_at DATETIME NOT NULL, payload_hash VARCHAR(32) NOT NULL, INDEX idx_tm_event_city (venue_city), INDEX idx_tm_event_segment (segment), INDEX idx_tm_event_start (event_start_utc), INDEX idx_tm_event_last_seen (last_seen_at), FULLTEXT INDEX ft_tm_event_name (name_normalized), PRIMARY KEY (event_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE tm_sale_window (id BINARY(16) NOT NULL, type VARCHAR(10) NOT NULL, label VARCHAR(120) DEFAULT \'\' NOT NULL, starts_at_utc DATETIME NOT NULL, ends_at_utc DATETIME DEFAULT NULL, url VARCHAR(500) DEFAULT NULL, event_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` NOT NULL, INDEX IDX_B14A7C3771F7E88B (event_id), INDEX idx_tm_sale_window_starts (starts_at_utc), UNIQUE INDEX uq_tm_sale_window_identity (event_id, type, label), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE event_watch (id BINARY(16) NOT NULL, notify_j1 TINYINT DEFAULT 1 NOT NULL, notify_h1 TINYINT DEFAULT 1 NOT NULL, channel VARCHAR(10) NOT NULL, created_at DATETIME NOT NULL, active TINYINT DEFAULT 1 NOT NULL, user_id BINARY(16) NOT NULL, event_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` NOT NULL, INDEX IDX_1110FB0AA76ED395 (user_id), INDEX IDX_1110FB0A71F7E88B (event_id), INDEX idx_event_watch_active (active), UNIQUE INDEX uq_event_watch_user_event (user_id, event_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE watch_notification (id BINARY(16) NOT NULL, type VARCHAR(20) NOT NULL, scheduled_for DATETIME NOT NULL, sent_at DATETIME DEFAULT NULL, acknowledged_at DATETIME DEFAULT NULL, status VARCHAR(12) NOT NULL, push_batch BINARY(16) DEFAULT NULL, created_at DATETIME NOT NULL, watch_id BINARY(16) NOT NULL, sale_window_id BINARY(16) DEFAULT NULL, INDEX IDX_B6F5DED6C7C58135 (watch_id), INDEX IDX_B6F5DED6B7142157 (sale_window_id), INDEX idx_watch_notification_due (status, scheduled_for), INDEX idx_watch_notification_batch (push_batch), INDEX idx_watch_notification_sent (sent_at), UNIQUE INDEX uq_watch_notification_slot (watch_id, sale_window_id, type, scheduled_for), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE tm_sale_window ADD CONSTRAINT FK_B14A7C3771F7E88B FOREIGN KEY (event_id) REFERENCES tm_event (event_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE event_watch ADD CONSTRAINT FK_1110FB0AA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE event_watch ADD CONSTRAINT FK_1110FB0A71F7E88B FOREIGN KEY (event_id) REFERENCES tm_event (event_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE watch_notification ADD CONSTRAINT FK_B6F5DED6C7C58135 FOREIGN KEY (watch_id) REFERENCES event_watch (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE watch_notification ADD CONSTRAINT FK_B6F5DED6B7142157 FOREIGN KEY (sale_window_id) REFERENCES tm_sale_window (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE watch_notification');
        $this->addSql('DROP TABLE event_watch');
        $this->addSql('DROP TABLE tm_sale_window');
        $this->addSql('DROP TABLE tm_event');
    }
}
