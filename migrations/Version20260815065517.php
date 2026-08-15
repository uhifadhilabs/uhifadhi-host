<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260815065517 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        // DEFAULT false backfills existing area_module rows; then drop it to match the entity mapping
        // (the column carries no DB default — the value is set in PHP).
        $this->addSql('ALTER TABLE area_module ADD viz_seeded BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE area_module ALTER viz_seeded DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE area_module DROP viz_seeded');
    }
}
