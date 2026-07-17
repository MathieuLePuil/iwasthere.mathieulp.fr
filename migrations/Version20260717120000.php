<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * L'équipe porte-bonheur : le nom de l'équipe que l'utilisateur suit, saisi
 * librement, pour le bilan « ton équipe gagne X% de ses matchs quand tu es au
 * stade ». Rien ne relie ce texte à un référentiel d'équipes — c'est le même
 * texte libre que le champ `teams` des événements, et le rapprochement se fait
 * par nom (voir App\Stats\LuckyTeam).
 */
final class Version20260717120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add favorite_team to user for the lucky-charm sport stat';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD favorite_team VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP favorite_team');
    }
}
