<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260601120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rebuild push_subscription table from scratch';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS push_subscription');
        $this->addSql('CREATE TABLE push_subscription (
            id BINARY(16) NOT NULL,
            user_id BINARY(16) NOT NULL,
            endpoint VARCHAR(500) NOT NULL,
            p256dh VARCHAR(255) NOT NULL,
            auth VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE INDEX uq_push_subscription_endpoint (endpoint),
            INDEX IDX_push_subscription_user (user_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE push_subscription ADD CONSTRAINT FK_push_subscription_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE push_subscription DROP FOREIGN KEY FK_push_subscription_user');
        $this->addSql('DROP TABLE push_subscription');
    }
}
