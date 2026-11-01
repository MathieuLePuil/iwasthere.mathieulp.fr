<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les clés étrangères qui bloquaient les suppressions.
 *
 * Retirer un événement de son journal (EventController::delete) faisait
 * remove($participation) alors que des réactions d'amis y pointaient encore :
 * RESTRICT par défaut, donc 500. Même chose côté admin pour les participations,
 * les événements et les lieux (event.venue_id).
 *
 * Une réaction n'a de sens que sur sa participation, elle tombe avec elle ; un
 * lieu supprimé laisse l'événement sans lieu plutôt que de le bloquer.
 */
final class Version20260912170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ON DELETE CASCADE on reaction FKs, ON DELETE SET NULL on event.venue_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reaction DROP FOREIGN KEY FK_A4D707F76ACE3B73');
        $this->addSql('ALTER TABLE reaction DROP FOREIGN KEY FK_A4D707F7A76ED395');
        $this->addSql('ALTER TABLE reaction ADD CONSTRAINT FK_A4D707F76ACE3B73 FOREIGN KEY (participation_id) REFERENCES event_participation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reaction ADD CONSTRAINT FK_A4D707F7A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE event DROP FOREIGN KEY FK_3BAE0AA740A73EBA');
        $this->addSql('ALTER TABLE event ADD CONSTRAINT FK_3BAE0AA740A73EBA FOREIGN KEY (venue_id) REFERENCES venue (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reaction DROP FOREIGN KEY FK_A4D707F76ACE3B73');
        $this->addSql('ALTER TABLE reaction DROP FOREIGN KEY FK_A4D707F7A76ED395');
        $this->addSql('ALTER TABLE reaction ADD CONSTRAINT FK_A4D707F76ACE3B73 FOREIGN KEY (participation_id) REFERENCES event_participation (id)');
        $this->addSql('ALTER TABLE reaction ADD CONSTRAINT FK_A4D707F7A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');

        $this->addSql('ALTER TABLE event DROP FOREIGN KEY FK_3BAE0AA740A73EBA');
        $this->addSql('ALTER TABLE event ADD CONSTRAINT FK_3BAE0AA740A73EBA FOREIGN KEY (venue_id) REFERENCES venue (id)');
    }
}
