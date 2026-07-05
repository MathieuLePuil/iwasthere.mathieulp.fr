<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260705110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move final_score, intermediate_scores and winner from event_participation to event (shared match data)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ADD final_score VARCHAR(100) DEFAULT NULL, ADD intermediate_scores JSON DEFAULT NULL, ADD winner VARCHAR(255) DEFAULT NULL');

        // Keep the value entered by the earliest participant who filled it in
        $this->addSql(<<<'SQL'
            UPDATE event e SET
                e.final_score = (SELECT ep.final_score FROM event_participation ep WHERE ep.event_id = e.id AND ep.final_score IS NOT NULL ORDER BY ep.created_at LIMIT 1),
                e.intermediate_scores = (SELECT ep.intermediate_scores FROM event_participation ep WHERE ep.event_id = e.id AND ep.intermediate_scores IS NOT NULL ORDER BY ep.created_at LIMIT 1),
                e.winner = (SELECT ep.winner FROM event_participation ep WHERE ep.event_id = e.id AND ep.winner IS NOT NULL ORDER BY ep.created_at LIMIT 1)
            SQL);

        $this->addSql('ALTER TABLE event_participation DROP final_score, DROP intermediate_scores, DROP winner');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event_participation ADD final_score VARCHAR(100) DEFAULT NULL, ADD intermediate_scores JSON DEFAULT NULL, ADD winner VARCHAR(255) DEFAULT NULL');

        $this->addSql(<<<'SQL'
            UPDATE event_participation ep
            JOIN event e ON e.id = ep.event_id
            SET ep.final_score = e.final_score,
                ep.intermediate_scores = e.intermediate_scores,
                ep.winner = e.winner
            SQL);

        $this->addSql('ALTER TABLE event DROP final_score, DROP intermediate_scores, DROP winner');
    }
}
