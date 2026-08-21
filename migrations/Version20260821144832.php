<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260821144832 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'uhakiki: Feature + Verdict move onto the bundle\'s TimestampableTrait (adds updated_at; created_at follows the trait\'s nullable shape).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE uhakiki_feature ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE uhakiki_feature ALTER created_at DROP NOT NULL');
        $this->addSql('ALTER TABLE uhakiki_verdict ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE uhakiki_verdict ALTER created_at DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE uhakiki_feature DROP updated_at');
        $this->addSql('ALTER TABLE uhakiki_feature ALTER created_at SET NOT NULL');
        $this->addSql('ALTER TABLE uhakiki_verdict DROP updated_at');
        $this->addSql('ALTER TABLE uhakiki_verdict ALTER created_at SET NOT NULL');
    }
}
