<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * event_participation.status (past/upcoming) disparaît : il recopiait la date
 * de l'événement et n'était recalé qu'à la visite de l'accueil, si bien que
 * les compteurs du profil et de la page publique divergeaient pour qui
 * n'avait pas ouvert l'app depuis son dernier événement. Tout se déduit de
 * event.date, qui reçoit un index pour ça.
 */
final class Version20260912174000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop event_participation.status; index event.date';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event_participation DROP status');
        $this->addSql('CREATE INDEX idx_event_date ON event (date)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_event_date ON event');
        $this->addSql('ALTER TABLE event_participation ADD status VARCHAR(20) NOT NULL DEFAULT \'past\'');
        $this->addSql('UPDATE event_participation p JOIN event e ON e.id = p.event_id SET p.status = IF(e.date >= CURDATE(), \'upcoming\', \'past\')');
    }
}
