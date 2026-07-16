<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La confidentialité se réduit à un compte public ou privé, à la manière d'Instagram.
 *
 * Ce qui disparaît, et pourquoi :
 *
 *  - `event_participation.visibility` : il n'y a jamais eu d'endroit dans l'app pour
 *    choisir la visibilité d'un événement — la colonne recopiait un réglage de compte.
 *    Un ami voit désormais tout, le feed et le classement n'ont plus rien à filtrer.
 *  - `user.event_visibility` : même raison, c'est le compte qui décide, pas l'événement.
 *  - `user.share_token` : le profil vit à /p/{pseudo}, une adresse stable. Un lien
 *    secret n'a plus d'objet, le cadenas remplace l'absence de page.
 *
 * Et `profile_visibility` passe de trois valeurs à deux :
 *
 *  - 'friends' (les amis voient l'historique) devient 'private' : c'est mot pour mot
 *    ce que « privé » signifie maintenant ;
 *  - 'private' (personne ne voyait rien) devient 'private' aussi. Ces comptes s'ouvrent
 *    donc à leurs amis. C'est une perte assumée : se cacher de ses propres amis n'existe
 *    plus, et aucun compte n'était dans ce cas au moment du passage.
 */
final class Version20260716150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reduce privacy to a public/private account switch';
    }

    public function up(Schema $schema): void
    {
        // Avant de changer le défaut de la colonne : 'friends' n'existe plus.
        $this->addSql("UPDATE `user` SET profile_visibility = 'private' WHERE profile_visibility != 'public'");

        $this->addSql('DROP INDEX UNIQ_8D93D649D6594DD6 ON `user`');
        $this->addSql("ALTER TABLE `user` DROP event_visibility, DROP share_token, CHANGE profile_visibility profile_visibility VARCHAR(20) DEFAULT 'private' NOT NULL");
        $this->addSql('ALTER TABLE event_participation DROP visibility');
    }

    /**
     * Les colonnes reviennent avec leur défaut d'origine — la visibilité que portait
     * chaque participation, elle, n'est enregistrée nulle part et ne peut pas revenir.
     * Le retour recopie donc la visibilité du compte, qui est très exactement ce que
     * ces colonnes contenaient avant qu'on les supprime.
     */
    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE `user` ADD event_visibility VARCHAR(20) DEFAULT 'friends' NOT NULL, ADD share_token VARCHAR(32) DEFAULT NULL, CHANGE profile_visibility profile_visibility VARCHAR(20) DEFAULT 'friends' NOT NULL");
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649D6594DD6 ON `user` (share_token)');
        $this->addSql("ALTER TABLE event_participation ADD visibility VARCHAR(20) DEFAULT 'friends' NOT NULL");

        $this->addSql("UPDATE `user` SET event_visibility = profile_visibility, profile_visibility = 'friends' WHERE profile_visibility = 'private'");
        $this->addSql("UPDATE `user` SET event_visibility = 'public' WHERE profile_visibility = 'public'");
        $this->addSql('UPDATE event_participation p JOIN `user` u ON u.id = p.user_id SET p.visibility = u.event_visibility');
    }
}
