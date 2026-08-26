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
 * Observation photos gain their preview key — the patrols module's adoption of
 * uhifadhilabs/storage-module.
 *
 * NULLABLE, and it must stay so: no GD build decodes HEIC and an ImageMagick
 * without libheif cannot either, so an iPhone photograph is routinely stored
 * with no preview available. Null says "there is no preview" plainly, rather
 * than a key pointing at a file that is not there; the page falls back to the
 * original.
 *
 * Nothing else moves. `storage_path` already holds a RELATIVE path and is
 * therefore already a valid evidence key: this deployment points
 * storage.evidence at the directory those photographs are in
 * (var/patrol/photos), so not one existing row is rewritten and not one
 * photograph goes dark. Rows written before this migration keep a null preview
 * until `patrol:photos:backfill-thumbs` makes one.
 */
final class Version20260826120422 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'patrol_observation_photo.thumb_key — the nullable evidence key of a photo’s ~400px preview';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE patrol_observation_photo ADD thumb_key VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // The previews themselves are left on disk: this reverses the schema,
        // not the deployment, and re-running the backfill would otherwise pay
        // for every thumbnail a second time.
        $this->addSql('ALTER TABLE patrol_observation_photo DROP thumb_key');
    }
}
