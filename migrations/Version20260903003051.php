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
 * The catalogue's icon column — and the only schema change the seam extraction
 * needed.
 *
 * The module seam moved out of this application and into uhifadhi/seam-module:
 * the Module and AreaModule entities, their repositories, the catalogue seed.
 * The TABLES did not move. `module` and `area_module` keep their names, their
 * columns and their constraints, the bundle writes those names out explicitly
 * rather than deriving them, and doctrine reports no difference — which is the
 * point: a rename here would have been a production migration on the platform's
 * central tables, paid for in downtime and bought with nothing but consistency.
 *
 * This one column is a real gap being closed rather than a move. The module
 * provider contract has always had an `icon()` method and the catalogue had
 * nowhere to put its answer, so every module's icon was silently discarded at
 * seed time. Nullable, because null is a real answer: it means "whatever the
 * host draws by default", decided at render time and not stored.
 */
final class Version20260903003051 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add module.icon — the provider contract\'s icon() finally has somewhere to land.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE module ADD icon VARCHAR(40) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE module DROP icon');
    }
}
