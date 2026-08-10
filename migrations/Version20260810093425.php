<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260810093425 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AreaOfInterest: public UUID (external addressing) + created/updated timestamps';
    }

    public function up(Schema $schema): void
    {
        // Add nullable first so existing rows survive, then backfill and enforce.
        $this->addSql('ALTER TABLE area_of_interest ADD uuid UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE area_of_interest ADD created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE area_of_interest ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        // Backfill existing rows (gen_random_uuid = v4; new rows get app-generated v7).
        $this->addSql('UPDATE area_of_interest SET uuid = gen_random_uuid() WHERE uuid IS NULL');
        $this->addSql('UPDATE area_of_interest SET created_at = NOW(), updated_at = NOW() WHERE created_at IS NULL');
        $this->addSql('ALTER TABLE area_of_interest ALTER COLUMN uuid SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_FB9E46FCD17F50A6 ON area_of_interest (uuid)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_FB9E46FCD17F50A6');
        $this->addSql('ALTER TABLE area_of_interest DROP uuid');
        $this->addSql('ALTER TABLE area_of_interest DROP created_at');
        $this->addSql('ALTER TABLE area_of_interest DROP updated_at');
    }
}
