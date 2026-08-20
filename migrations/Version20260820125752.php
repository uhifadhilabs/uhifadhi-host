<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260820125752 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'uhakiki: campaigns own which source property carries a feature\'s reference.';
    }

    public function up(Schema $schema): void
    {
        // Nullable and additive: existing campaigns keep importing without a
        // source reference until one is configured for them.
        $this->addSql('ALTER TABLE uhakiki_campaign ADD source_ref_property VARCHAR(120) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE uhakiki_campaign DROP source_ref_property');
    }
}
