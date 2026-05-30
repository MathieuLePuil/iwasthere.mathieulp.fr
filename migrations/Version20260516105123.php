<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260516105123 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE audit_log (id BINARY(16) NOT NULL, super_admin_user_id BINARY(16) NOT NULL, action VARCHAR(50) NOT NULL, entity_type VARCHAR(100) NOT NULL, entity_id VARCHAR(255) NOT NULL, field_changed VARCHAR(255) DEFAULT NULL, old_value LONGTEXT DEFAULT NULL, new_value LONGTEXT DEFAULT NULL, performed_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE event (id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, category VARCHAR(20) NOT NULL, type VARCHAR(20) NOT NULL, date DATETIME NOT NULL, start_time VARCHAR(5) DEFAULT NULL, venue_id BINARY(16) DEFAULT NULL, artist_name VARCHAR(255) DEFAULT NULL, tournament_name VARCHAR(255) DEFAULT NULL, teams VARCHAR(255) DEFAULT NULL, setlist JSON DEFAULT NULL, setlist_encores JSON DEFAULT NULL, setlist_source VARCHAR(20) DEFAULT NULL, setlist_url VARCHAR(500) DEFAULT NULL, setlist_imported_at DATETIME DEFAULT NULL, setlist_last_attempt_at DATETIME DEFAULT NULL, setlist_retry_count INT DEFAULT 0 NOT NULL, participant_count INT DEFAULT 0 NOT NULL, edit_history JSON NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX IDX_3BAE0AA740A73EBA (venue_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE event_participation (id BINARY(16) NOT NULL, status VARCHAR(20) NOT NULL, rating INT DEFAULT NULL, comment LONGTEXT DEFAULT NULL, duration INT DEFAULT NULL, ticket_price DOUBLE PRECISION DEFAULT NULL, ticket_platform VARCHAR(100) DEFAULT NULL, ticket_purchase_platform VARCHAR(100) DEFAULT NULL, friends JSON NOT NULL, photos JSON NOT NULL, final_score VARCHAR(100) DEFAULT NULL, intermediate_scores JSON DEFAULT NULL, winner VARCHAR(255) DEFAULT NULL, visibility VARCHAR(20) DEFAULT \'friends\' NOT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, event_id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL, INDEX IDX_8F0C52E371F7E88B (event_id), INDEX IDX_8F0C52E3A76ED395 (user_id), UNIQUE INDEX uq_event_participation (event_id, user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE friend (id BINARY(16) NOT NULL, friend_type VARCHAR(20) NOT NULL, status VARCHAR(20) DEFAULT NULL, display_name VARCHAR(100) DEFAULT NULL, created_at DATETIME NOT NULL, owner_id BINARY(16) NOT NULL, friend_user_id BINARY(16) DEFAULT NULL, INDEX IDX_55EEAC617E3C61F9 (owner_id), INDEX IDX_55EEAC6193D1119E (friend_user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE notification (id BINARY(16) NOT NULL, type VARCHAR(50) NOT NULL, title VARCHAR(255) NOT NULL, body LONGTEXT NOT NULL, data JSON DEFAULT NULL, is_read TINYINT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, recipient_id BINARY(16) NOT NULL, INDEX IDX_BF5476CAE92F8F78 (recipient_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `user` (id BINARY(16) NOT NULL, username VARCHAR(50) NOT NULL, display_name VARCHAR(100) NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) DEFAULT NULL, google_id VARCHAR(255) DEFAULT NULL, avatar_url VARCHAR(255) DEFAULT NULL, bio LONGTEXT DEFAULT NULL, profile_visibility VARCHAR(20) DEFAULT \'friends\' NOT NULL, default_event_visibility VARCHAR(20) DEFAULT \'friends\' NOT NULL, notifications_enabled TINYINT DEFAULT 1 NOT NULL, notif_completion_enabled TINYINT DEFAULT 1 NOT NULL, notif_completion_time VARCHAR(5) DEFAULT \'08:00\', notif_presence_enabled TINYINT DEFAULT 1 NOT NULL, notif_friend_request_enabled TINYINT DEFAULT 1 NOT NULL, setlist_auto_import TINYINT DEFAULT 1 NOT NULL, setlist_show_encores TINYINT DEFAULT 1 NOT NULL, role VARCHAR(20) DEFAULT \'user\' NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_8D93D649F85E0677 (username), UNIQUE INDEX UNIQ_8D93D649E7927C74 (email), UNIQUE INDEX UNIQ_8D93D64976F5C865 (google_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE venue (id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, name VARCHAR(255) NOT NULL, address VARCHAR(255) NOT NULL, city VARCHAR(100) NOT NULL, country VARCHAR(100) NOT NULL, latitude DOUBLE PRECISION NOT NULL, longitude DOUBLE PRECISION NOT NULL, capacity INT DEFAULT NULL, venue_type VARCHAR(20) DEFAULT NULL, edit_history JSON NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE event ADD CONSTRAINT FK_3BAE0AA740A73EBA FOREIGN KEY (venue_id) REFERENCES venue (id)');
        $this->addSql('ALTER TABLE event_participation ADD CONSTRAINT FK_8F0C52E371F7E88B FOREIGN KEY (event_id) REFERENCES event (id)');
        $this->addSql('ALTER TABLE event_participation ADD CONSTRAINT FK_8F0C52E3A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE friend ADD CONSTRAINT FK_55EEAC617E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE friend ADD CONSTRAINT FK_55EEAC6193D1119E FOREIGN KEY (friend_user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAE92F8F78 FOREIGN KEY (recipient_id) REFERENCES `user` (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE event DROP FOREIGN KEY FK_3BAE0AA740A73EBA');
        $this->addSql('ALTER TABLE event_participation DROP FOREIGN KEY FK_8F0C52E371F7E88B');
        $this->addSql('ALTER TABLE event_participation DROP FOREIGN KEY FK_8F0C52E3A76ED395');
        $this->addSql('ALTER TABLE friend DROP FOREIGN KEY FK_55EEAC617E3C61F9');
        $this->addSql('ALTER TABLE friend DROP FOREIGN KEY FK_55EEAC6193D1119E');
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CAE92F8F78');
        $this->addSql('DROP TABLE audit_log');
        $this->addSql('DROP TABLE event');
        $this->addSql('DROP TABLE event_participation');
        $this->addSql('DROP TABLE friend');
        $this->addSql('DROP TABLE notification');
        $this->addSql('DROP TABLE `user`');
        $this->addSql('DROP TABLE venue');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
