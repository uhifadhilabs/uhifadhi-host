<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260814112911 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bind a Visualization to a dataset (visualization.dataset_key); xAxis/yAxis become column names.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE visualization ADD dataset_key VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE visualization DROP dataset_key');
    }
}
