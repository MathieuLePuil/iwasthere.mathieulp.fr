<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le pays d'un lieu ne servait à rien : toutes les venues sont en France, et la
 * valeur était forcée à 'France' à la création. Le down() la remet donc à
 * 'France' partout — c'est la seule valeur qui ait jamais existé.
 */
final class Version20260715201500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop country from venue';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE venue DROP country');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE venue ADD country VARCHAR(100) DEFAULT 'France' NOT NULL");
    }
}
