<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260809094658 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create comments, banned users, and Messenger queues.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE banned_users (user_id VARCHAR(100) NOT NULL, banned_at DATETIME NOT NULL, PRIMARY KEY (user_id))');
        $this->addSql('CREATE TABLE comments (id VARCHAR(36) NOT NULL, publisher VARCHAR(100) NOT NULL, source_id VARCHAR(255) NOT NULL, author_id VARCHAR(100) DEFAULT NULL, body CLOB NOT NULL, status VARCHAR(255) NOT NULL, moderation_reason VARCHAR(100) DEFAULT NULL, created_at DATETIME NOT NULL, moderated_at DATETIME DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_comments_publisher ON comments (publisher)');
        $this->addSql('CREATE INDEX idx_comments_status ON comments (status)');
        $this->addSql('CREATE TABLE messenger_messages (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, body CLOB NOT NULL, headers CLOB NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE banned_users');
        $this->addSql('DROP TABLE comments');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
