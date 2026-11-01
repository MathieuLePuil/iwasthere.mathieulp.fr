<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Aligne les participations existantes sur la visibilité de leur compte.
 *
 * L'invariant est désormais : toutes les participations d'un compte portent la
 * visibilité du compte. Il tenait de lui-même tant que la valeur n'était copiée qu'à
 * la création, mais un compte qui changeait son réglage gardait son historique à
 * l'ancienne valeur — un profil réglé sur « public » restait ainsi vide de tout
 * événement public. SettingsController::savePrivacy réaligne maintenant à chaque
 * changement ; cette migration rattrape ceux d'avant.
 *
 * Sans effet sur une base déjà cohérente, et rejouable.
 */
final class Version20260716140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Realign event_participation.visibility with user.event_visibility';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'UPDATE event_participation p
             JOIN `user` u ON u.id = p.user_id
             SET p.visibility = u.event_visibility
             WHERE p.visibility != u.event_visibility'
        );
    }

    /**
     * Irréversible : la visibilité que portait chaque participation avant l'alignement
     * n'est enregistrée nulle part. Ne rien faire est le seul retour honnête — et sans
     * conséquence, la colonne restant parfaitement valide telle quelle.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('SELECT 1');
    }
}
