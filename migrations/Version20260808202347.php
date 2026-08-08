<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260808202347 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Per-area loss: forest_loss_year.aoi_id (NOT NULL, CASCADE) + dataset_run.aoi_id (nullable, SET NULL); wipes pre-multi-area rows';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        // Decision 2026-08-08: wipe existing (pre-multi-area) loss rows — the NCA is
        // re-ingested through the new per-area UI as the first end-to-end proof.
        $this->addSql('DELETE FROM forest_loss_year');
        $this->addSql('ALTER TABLE dataset_run ADD aoi_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE dataset_run ADD CONSTRAINT FK_B1137992B626396B FOREIGN KEY (aoi_id) REFERENCES area_of_interest (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_B1137992B626396B ON dataset_run (aoi_id)');
        $this->addSql('ALTER TABLE forest_loss_year ADD aoi_id INT NOT NULL');
        $this->addSql('ALTER TABLE forest_loss_year ADD CONSTRAINT FK_1E1F1912B626396B FOREIGN KEY (aoi_id) REFERENCES area_of_interest (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_1E1F1912B626396B ON forest_loss_year (aoi_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dataset_run DROP CONSTRAINT FK_B1137992B626396B');
        $this->addSql('DROP INDEX IDX_B1137992B626396B');
        $this->addSql('ALTER TABLE dataset_run DROP aoi_id');
        $this->addSql('ALTER TABLE forest_loss_year DROP CONSTRAINT FK_1E1F1912B626396B');
        $this->addSql('DROP INDEX IDX_1E1F1912B626396B');
        $this->addSql('ALTER TABLE forest_loss_year DROP aoi_id');
    }
}
