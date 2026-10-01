<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260920000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mailbox epoch and metadata-only draft processing state';
    }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mailbox_state (id VARCHAR(64) NOT NULL PRIMARY KEY, uid_validity VARCHAR(32) NOT NULL)');
        $this->addSql('CREATE TABLE processing_record (id VARCHAR(64) NOT NULL PRIMARY KEY, mailbox VARCHAR(64) NOT NULL, uid_validity VARCHAR(32) NOT NULL, source_uid INTEGER NOT NULL, source_message_id VARCHAR(255) DEFAULT NULL, status VARCHAR(32) NOT NULL, attempts INTEGER NOT NULL, draft_message_id VARCHAR(255) DEFAULT NULL, draft_uid VARCHAR(32) DEFAULT NULL, config_hash VARCHAR(64) NOT NULL, selected_ids CLOB NOT NULL, reason VARCHAR(80) DEFAULT NULL, error VARCHAR(160) DEFAULT NULL, flag_pending BOOLEAN NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX source_identity ON processing_record (mailbox, uid_validity, source_uid)');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE processing_record');
        $this->addSql('DROP TABLE mailbox_state');
    }
}
