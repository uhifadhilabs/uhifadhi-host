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

namespace Uhifadhi\Tests\Integration;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Entity\WidgetCustomPreset;

/**
 * The stored half of CUSTOM presets: a person's saved layouts, and the database
 * — not the application — is what keeps one name meaning one layout.
 *
 * The difficulty is {@see WidgetPreferenceTest}'s, one column wider: the area is
 * nullable and Postgres counts NULLs as DISTINCT, so a plain
 * UNIQUE (surface, user_id, area_uuid, name) would not constrain the org-wide
 * rows at all — "save as Morning check" would grow a second card with the same
 * word on it instead of replacing the first. Hence two partial unique indexes
 * over the two disjoint cases, proven here.
 */
final class WidgetCustomPresetTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $this->entityManager = $entityManager;

        $tool = new SchemaTool($this->entityManager);
        $tool->dropSchema([$this->metadata()]);
        $tool->createSchema([$this->metadata()]);
    }

    protected function tearDown(): void
    {
        new SchemaTool($this->entityManager)->dropSchema([$this->metadata()]);
        $this->entityManager->clear();

        parent::tearDown();
    }

    /**
     * @return \Doctrine\ORM\Mapping\ClassMetadata<WidgetCustomPreset>
     */
    private function metadata(): \Doctrine\ORM\Mapping\ClassMetadata
    {
        return $this->entityManager->getClassMetadata(WidgetCustomPreset::class);
    }

    public function testTheTableCarriesBothPartialUniqueIndexes(): void
    {
        $sql = implode("\n", new SchemaTool($this->entityManager)->getCreateSchemaSql([$this->metadata()]));

        self::assertStringContainsString(
            'CREATE UNIQUE INDEX uniq_widget_preset_surface_user_area_name ON widget_custom_preset (surface, user_id, area_uuid, name) WHERE (area_uuid IS NOT NULL)',
            $sql,
        );
        self::assertStringContainsString(
            'CREATE UNIQUE INDEX uniq_widget_preset_surface_user_org_name ON widget_custom_preset (surface, user_id, name) WHERE (area_uuid IS NULL)',
            $sql,
        );
    }

    public function testTwoPeopleMayUseTheSameNameAndOnePersonMayNot(): void
    {
        $this->entityManager->persist(new WidgetCustomPreset('departments', 1, null, 'Morning check', ['kpis' => 12]));
        // The same words on another person's dashboard are not a clash.
        $this->entityManager->persist(new WidgetCustomPreset('departments', 2, null, 'Morning check', ['cards' => 12]));
        // Nor on another surface, or another area.
        $this->entityManager->persist(new WidgetCustomPreset('patrols', 1, null, 'Morning check', ['kpis' => 12]));
        $this->entityManager->persist(new WidgetCustomPreset('patrols', 1, Uuid::v7(), 'Morning check', ['kpis' => 12]));
        $this->entityManager->flush();

        // The same person, surface and (org-wide) scope is one preset, though —
        // which is what makes "save under a name you used" a replacement.
        $this->expectException(UniqueConstraintViolationException::class);
        $this->entityManager->persist(new WidgetCustomPreset('departments', 1, null, 'Morning check', ['lens' => 12]));
        $this->entityManager->flush();
    }

    public function testAPresetIsAddressedByAUuidAndStampedWithTimestamps(): void
    {
        $preset = new WidgetCustomPreset('departments', 1, null, 'Morning check', ['kpis' => 12, 'cards' => 12]);
        $this->entityManager->persist($preset);
        $this->entityManager->flush();

        self::assertNotNull($preset->getUuid(), 'the sequential id is never the external address');
        self::assertNotNull($preset->getCreatedAt());
        self::assertSame(['kpis' => 12, 'cards' => 12], $preset->getLayout());
    }
}
