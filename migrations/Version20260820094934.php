<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * uhakiki: Candidate → Feature.
 *
 * "Candidate" read as a person; the records are georeferenced features — a
 * boma, a well, a carcass — so the entity was renamed and the table with it.
 *
 * Doctrine's diff cannot tell a rename from a drop+create and generated
 * DROP TABLE uhakiki_candidate, which would discard every imported feature
 * and cascade to its verdicts. This is hand-written as a true RENAME so the
 * data survives; the indexes and constraints are renamed alongside the table
 * to match the identifiers Doctrine now derives from "uhakiki_feature".
 */
final class Version20260820094934 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename uhakiki_candidate to uhakiki_feature (data-preserving), and verdict.candidate_id to feature_id.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE uhakiki_candidate RENAME TO uhakiki_feature');

        $this->addSql('ALTER INDEX uniq_b61c1c67d17f50a6 RENAME TO UNIQ_2BCA16B5D17F50A6');
        $this->addSql('ALTER INDEX idx_b61c1c67f639f774 RENAME TO IDX_2BCA16B5F639F774');
        $this->addSql('ALTER INDEX idx_uhakiki_candidate_point_sp RENAME TO idx_uhakiki_feature_point_sp');
        $this->addSql('ALTER INDEX idx_uhakiki_candidate_resolved_point_sp RENAME TO idx_uhakiki_feature_resolved_point_sp');
        $this->addSql('ALTER TABLE uhakiki_feature RENAME CONSTRAINT fk_b61c1c67f639f774 TO FK_2BCA16B5F639F774');

        $this->addSql('ALTER TABLE uhakiki_verdict RENAME COLUMN candidate_id TO feature_id');
        $this->addSql('ALTER INDEX idx_1718694091bd8781 RENAME TO IDX_1718694060E4B879');
        $this->addSql('ALTER TABLE uhakiki_verdict RENAME CONSTRAINT fk_1718694091bd8781 TO FK_1718694060E4B879');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE uhakiki_verdict RENAME CONSTRAINT FK_1718694060E4B879 TO fk_1718694091bd8781');
        $this->addSql('ALTER INDEX IDX_1718694060E4B879 RENAME TO idx_1718694091bd8781');
        $this->addSql('ALTER TABLE uhakiki_verdict RENAME COLUMN feature_id TO candidate_id');

        $this->addSql('ALTER TABLE uhakiki_feature RENAME CONSTRAINT FK_2BCA16B5F639F774 TO fk_b61c1c67f639f774');
        $this->addSql('ALTER INDEX idx_uhakiki_feature_resolved_point_sp RENAME TO idx_uhakiki_candidate_resolved_point_sp');
        $this->addSql('ALTER INDEX idx_uhakiki_feature_point_sp RENAME TO idx_uhakiki_candidate_point_sp');
        $this->addSql('ALTER INDEX IDX_2BCA16B5F639F774 RENAME TO idx_b61c1c67f639f774');
        $this->addSql('ALTER INDEX UNIQ_2BCA16B5D17F50A6 RENAME TO uniq_b61c1c67d17f50a6');

        $this->addSql('ALTER TABLE uhakiki_feature RENAME TO uhakiki_candidate');
    }
}
