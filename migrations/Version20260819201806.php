<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260819201806 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'uhakiki: replace campaign.area_ref (opaque uuid string) with a real FK to area_of_interest';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE uhakiki_campaign ADD area_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE uhakiki_campaign DROP area_ref');
        $this->addSql('ALTER TABLE uhakiki_campaign ADD CONSTRAINT FK_FB8CD0BD0F409C FOREIGN KEY (area_id) REFERENCES area_of_interest (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_FB8CD0BD0F409C ON uhakiki_campaign (area_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE uhakiki_campaign DROP CONSTRAINT FK_FB8CD0BD0F409C');
        $this->addSql('DROP INDEX IDX_FB8CD0BD0F409C');
        $this->addSql('ALTER TABLE uhakiki_campaign ADD area_ref VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE uhakiki_campaign DROP area_id');
    }
}
