<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La confidentialité binaire `profile_visibility` (public/private) devient une carte
 * JSON `privacy_settings` réglable par catégorie (events, stats, friends), chaque
 * valeur étant private | friends | public.
 *
 * Reprise des données depuis l'ancien réglage :
 *  - public  -> événements et stats ouverts à tous, liste d'amis réservée aux amis ;
 *  - sinon   -> tout réservé aux amis (l'ancien compte privé).
 * La liste d'amis n'est jamais ouverte au public d'office : on n'expose pas une
 * information que l'ancien modèle ne montrait à personne.
 */
final class Version20260722120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace profile_visibility (string) with privacy_settings (JSON audience per category)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD privacy_settings JSON DEFAULT NULL');
        $this->addSql("UPDATE `user` SET privacy_settings = CASE
            WHEN profile_visibility = 'public'
                THEN '{\"events\":\"public\",\"stats\":\"public\",\"friends\":\"friends\"}'
            ELSE '{\"events\":\"friends\",\"stats\":\"friends\",\"friends\":\"friends\"}'
        END");
        $this->addSql('ALTER TABLE `user` MODIFY privacy_settings JSON NOT NULL');
        $this->addSql('ALTER TABLE `user` DROP profile_visibility');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE `user` ADD profile_visibility VARCHAR(20) DEFAULT 'private' NOT NULL");
        $this->addSql("UPDATE `user` SET profile_visibility = CASE
            WHEN JSON_UNQUOTE(JSON_EXTRACT(privacy_settings, '$.events')) = 'public'
                THEN 'public'
            ELSE 'private'
        END");
        $this->addSql('ALTER TABLE `user` DROP privacy_settings');
    }
}
