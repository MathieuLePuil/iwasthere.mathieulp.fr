<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les réactions stockaient un code d'un catalogue fermé ('fire', 'heart', 'party') ;
 * elles stockent désormais l'emoji lui-même, puisqu'on peut mettre celui qu'on veut.
 *
 * La colonne ne bouge pas (VARCHAR(20) utf8mb4, assez large pour une famille ZWJ ou
 * un drapeau à balises) : seules les valeurs sont converties. Les lignes déjà posées
 * en production afficheraient sinon « fire » en toutes lettres dans le feed.
 */
final class Version20260716120000 extends AbstractMigration
{
    /** @var array<string, string> */
    private const CODES = ['fire' => '🔥', 'heart' => '❤️', 'party' => '🎉'];

    public function getDescription(): string
    {
        return 'Convert reaction codes to emoji characters';
    }

    public function up(Schema $schema): void
    {
        foreach (self::CODES as $code => $emoji) {
            $this->addSql('UPDATE reaction SET emoji = :emoji WHERE emoji = :code', ['emoji' => $emoji, 'code' => $code]);
        }
    }

    /**
     * Le retour n'est fidèle que pour les trois emojis du catalogue d'origine : une
     * réaction libre (🦄) n'a pas de code où retomber. Elles sont supprimées plutôt
     * que laissées derrière — l'ancien code les aurait de toute façon rejetées à la
     * lecture, l'enum n'ayant pas de cas correspondant.
     */
    public function down(Schema $schema): void
    {
        foreach (self::CODES as $code => $emoji) {
            $this->addSql('UPDATE reaction SET emoji = :code WHERE emoji = :emoji', ['code' => $code, 'emoji' => $emoji]);
        }

        $this->addSql('DELETE FROM reaction WHERE emoji NOT IN (:codes)', ['codes' => array_keys(self::CODES)], ['codes' => \Doctrine\DBAL\ArrayParameterType::STRING]);
    }
}
