<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `default_event_visibility` n'était plus un défaut : la visibilité vaut pour tout
 * l'historique d'un compte, et la changer réaligne les participations existantes
 * (SettingsController::savePrivacy). Le nom promettait un réglage de départ pour les
 * prochains événements, ce qui laissait croire à un choix par événement — il n'y en a
 * jamais eu, et c'est ce malentendu qui faisait des profils publics vides.
 *
 * Renommage seul : le contenu de la colonne reste valide tel quel.
 */
final class Version20260716130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename user.default_event_visibility to event_visibility';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE `user` CHANGE default_event_visibility event_visibility VARCHAR(20) DEFAULT 'friends' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE `user` CHANGE event_visibility default_event_visibility VARCHAR(20) DEFAULT 'friends' NOT NULL");
    }
}
