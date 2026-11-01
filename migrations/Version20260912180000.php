<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La clé de dédoublonnage d'une notification sort du JSON `data` pour une
 * colonne indexée : chaque envoi vérifiait « déjà envoyée ? » en chargeant
 * toutes les notifications du type pour le destinataire. Index aussi sur
 * (recipient, is_read), interrogé par le badge toutes les minutes.
 */
final class Version20260912180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'notification.dedupe_key (backfilled from data JSON) + indexes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification ADD dedupe_key VARCHAR(120) DEFAULT NULL');
        $this->addSql("UPDATE notification SET dedupe_key = JSON_UNQUOTE(JSON_EXTRACT(data, '$.dedupeKey')) WHERE data IS NOT NULL AND JSON_EXTRACT(data, '$.dedupeKey') IS NOT NULL");
        $this->addSql('CREATE INDEX idx_notification_unread ON notification (recipient_id, is_read)');
        $this->addSql('CREATE INDEX idx_notification_dedupe ON notification (recipient_id, type, dedupe_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_notification_dedupe ON notification');
        $this->addSql('DROP INDEX idx_notification_unread ON notification');
        $this->addSql('ALTER TABLE notification DROP dedupe_key');
    }
}
