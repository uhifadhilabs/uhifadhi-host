<?php

declare(strict_types=1);

/*
 * This file is part of uhifadhi.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The operational modules move to the Operations category.
 *
 * NO SCHEMA CHANGES. `module.category` is a plain string column with the enum
 * read in PHP, so a fourth case costs the database nothing. What DOES need
 * saying is the data: patrols and incidents shipped filed under "pressure",
 * which in this catalogue means human pressure ON the ecosystem — a category
 * error for the rangers' own work, and the reason Operations now exists.
 *
 * WHY A MIGRATION AND NOT THE SEED. `app:seed:catalogue` upserts every module's
 * category on every run and would fix these rows in one command — but nothing
 * runs it on deploy. The post-deploy hook runs migrations only, so a deployed
 * catalogue would keep saying "pressure" until somebody remembered to seed by
 * hand. Migrations are the only thing that certainly runs, so the correction
 * travels in one.
 *
 * BY SLUG, NOT BY CATEGORY. Only the two modules whose own default changed are
 * moved; a deployment that deliberately filed something else under pressure
 * keeps it there.
 */
final class Version20260902090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Catalogue: patrols and incidents are Operations, not Pressure';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE module SET category = 'operations' WHERE slug IN ('patrols', 'incidents') AND category = 'pressure'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE module SET category = 'pressure' WHERE slug IN ('patrols', 'incidents') AND category = 'operations'");
    }
}
