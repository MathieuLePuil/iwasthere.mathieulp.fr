<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les réactions posées sur la participation d'un ami.
 *
 * L'unicité porte sur (participation, auteur, emoji) : chacun peut cumuler
 * plusieurs emojis sur le même souvenir, mais pas poser deux fois le même —
 * c'est ce que la bascule côté client suppose.
 *
 * Les clés étrangères restent en RESTRICT comme le reste du schéma : le ménage
 * est fait explicitement par AccountDeletionService, qui doit garder la main
 * (cf. le compteur dénormalisé event.participant_count).
 */
final class Version20260716100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reaction table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE reaction (id BINARY(16) NOT NULL, emoji VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, participation_id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL, INDEX IDX_A4D707F76ACE3B73 (participation_id), INDEX IDX_A4D707F7A76ED395 (user_id), UNIQUE INDEX uq_reaction (participation_id, user_id, emoji), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE reaction ADD CONSTRAINT FK_A4D707F76ACE3B73 FOREIGN KEY (participation_id) REFERENCES event_participation (id)');
        $this->addSql('ALTER TABLE reaction ADD CONSTRAINT FK_A4D707F7A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reaction DROP FOREIGN KEY FK_A4D707F76ACE3B73');
        $this->addSql('ALTER TABLE reaction DROP FOREIGN KEY FK_A4D707F7A76ED395');
        $this->addSql('DROP TABLE reaction');
    }
}
