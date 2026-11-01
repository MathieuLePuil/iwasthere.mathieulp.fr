<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Une équipe porte-bonheur par sport collectif plutôt qu'une seule : la chaîne
 * `favorite_team` devient une map JSON `favorite_teams` (sport => nom d'équipe),
 * par exemple {"football": "ESTAC", "rugby": "Stade Français"}.
 *
 * Aucune donnée à reprendre : la colonne précédente n'a jamais été renseignée.
 */
final class Version20260717130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move favorite_team (string) to favorite_teams (JSON map by sport)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD favorite_teams JSON DEFAULT NULL');
        $this->addSql("UPDATE `user` SET favorite_teams = '{}'");
        $this->addSql('ALTER TABLE `user` MODIFY favorite_teams JSON NOT NULL');
        $this->addSql('ALTER TABLE `user` DROP favorite_team');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD favorite_team VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE `user` DROP favorite_teams');
    }
}
