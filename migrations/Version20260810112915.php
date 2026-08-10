<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260810112915 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE area_of_interest ADD iucn_category VARCHAR(8) DEFAULT NULL');
        $this->addSql('ALTER TABLE area_of_interest ADD established_year INT DEFAULT NULL');
        $this->addSql('ALTER TABLE area_of_interest ADD tree_cover_pct DOUBLE PRECISION DEFAULT NULL');
        // Backfill the two real areas from their WDPA records (IUCN category + gazette
        // year are authoritative; tree-cover % is the known figure until a Hansen
        // tree-cover ingestion computes it per boundary).
        $this->addSql("UPDATE area_of_interest SET iucn_category = 'VI', established_year = 1959, tree_cover_pct = 11 WHERE name = 'Ngorongoro Conservation Area'");
        $this->addSql("UPDATE area_of_interest SET iucn_category = 'II', established_year = 1968, tree_cover_pct = 85 WHERE name = 'Gombe National Park'");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE area_of_interest DROP iucn_category');
        $this->addSql('ALTER TABLE area_of_interest DROP established_year');
        $this->addSql('ALTER TABLE area_of_interest DROP tree_cover_pct');
    }
}
