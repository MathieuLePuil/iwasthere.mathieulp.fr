<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260530100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add password reset token to user; add push_subscription table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD password_reset_token VARCHAR(255) DEFAULT NULL, ADD password_reset_token_expires_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE TABLE push_subscription (id BINARY(16) NOT NULL, endpoint LONGTEXT NOT NULL, p256dh VARCHAR(512) NOT NULL, auth VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, user_id BINARY(16) NOT NULL, INDEX IDX_PS_USER (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE push_subscription ADD CONSTRAINT FK_PS_USER FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE push_subscription DROP FOREIGN KEY FK_PS_USER');
        $this->addSql('DROP TABLE push_subscription');
        $this->addSql('ALTER TABLE `user` DROP password_reset_token, DROP password_reset_token_expires_at');
    }
}
