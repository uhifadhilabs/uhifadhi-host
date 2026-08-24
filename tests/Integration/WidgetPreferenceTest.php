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
use Uhifadhi\Entity\WidgetPreference;
use Uhifadhi\Model\Widget;
use Uhifadhi\Model\WidgetCatalog;
use Uhifadhi\Model\WidgetGroup;
use Uhifadhi\Repository\WidgetCustomPresetRepository;
use Uhifadhi\Repository\WidgetPreferenceRepository;
use Uhifadhi\Service\WidgetService;

/**
 * The stored half of the widget framework: one row per person per surface, and
 * the database — not the application — is what guarantees it.
 *
 * The nullable area is the whole difficulty. Postgres treats NULLs as DISTINCT
 * in a unique index, so a plain UNIQUE (surface, user_id, area_uuid) would
 * happily accept two org-wide rows for the same person on the same dashboard —
 * a second row that read() would never see and reset() would never delete. The
 * mapping therefore declares TWO partial unique indexes over the two disjoint
 * cases, and the tests below prove both halves.
 *
 * The table is created from the mapping here rather than assumed: this wave
 * ships no migration (the surface that first uses the entity brings one), so the
 * test builds the schema it needs and drops it again.
 */
final class WidgetPreferenceTest extends KernelTestCase
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
     * @return \Doctrine\ORM\Mapping\ClassMetadata<WidgetPreference>
     */
    private function metadata(): \Doctrine\ORM\Mapping\ClassMetadata
    {
        return $this->entityManager->getClassMetadata(WidgetPreference::class);
    }

    private function repository(): WidgetPreferenceRepository
    {
        $repository = self::getContainer()->get(WidgetPreferenceRepository::class);
        \assert($repository instanceof WidgetPreferenceRepository);

        return $repository;
    }

    private static function catalog(): WidgetCatalog
    {
        return new WidgetCatalog('departments', [new WidgetGroup('shape', 'Shape', 'How the org is arranged.')], [
            new Widget('tree', 'Department tree', 'shape'),
            new Widget('vacancies', 'Vacant positions', 'shape', cols: 6, spans: [9, 6, 3]),
        ]);
    }

    private function savedPresets(): WidgetCustomPresetRepository
    {
        $repository = self::getContainer()->get(WidgetCustomPresetRepository::class);
        \assert($repository instanceof WidgetCustomPresetRepository);

        return $repository;
    }

    /**
     * Built here rather than fetched: nothing in the host injects it yet (the
     * first dashboard surface will), so the container inlines it away.
     */
    private function widgets(): WidgetService
    {
        return new WidgetService($this->repository(), $this->savedPresets(), $this->entityManager);
    }

    public function testTheTableCarriesBothPartialUniqueIndexes(): void
    {
        $sql = implode("\n", new SchemaTool($this->entityManager)->getCreateSchemaSql([$this->metadata()]));

        // The area-scoped half…
        self::assertStringContainsString(
            'CREATE UNIQUE INDEX uniq_widget_pref_surface_user_area ON widget_preference (surface, user_id, area_uuid) WHERE (area_uuid IS NOT NULL)',
            $sql,
        );
        // …and the org-wide half, which the three-column index cannot police
        // because Postgres counts NULLs as distinct.
        self::assertStringContainsString(
            'CREATE UNIQUE INDEX uniq_widget_pref_surface_user_org ON widget_preference (surface, user_id) WHERE (area_uuid IS NULL)',
            $sql,
        );
    }

    public function testTwoOrgWideRowsForOnePersonAndSurfaceAreImpossible(): void
    {
        $this->entityManager->persist(new WidgetPreference('departments', 7, null, ['order' => []]));
        $this->entityManager->flush();

        $this->expectException(UniqueConstraintViolationException::class);

        $this->entityManager->persist(new WidgetPreference('departments', 7, null, ['order' => []]));
        $this->entityManager->flush();
    }

    public function testTwoRowsForOnePersonSurfaceAndAreaAreImpossible(): void
    {
        $area = Uuid::v7();
        $this->entityManager->persist(new WidgetPreference('patrols', 7, $area, ['order' => []]));
        $this->entityManager->flush();

        $this->expectException(UniqueConstraintViolationException::class);

        $this->entityManager->persist(new WidgetPreference('patrols', 7, $area, ['order' => []]));
        $this->entityManager->flush();
    }

    public function testTheSameSurfaceInAnotherAreaForAnotherPersonOrAnotherSurfaceIsAllowed(): void
    {
        $area = Uuid::v7();
        $this->entityManager->persist(new WidgetPreference('patrols', 7, $area));
        // Another area, another person, another surface — and the org-wide row of
        // the same surface, which is a different layout, not a duplicate.
        $this->entityManager->persist(new WidgetPreference('patrols', 7, Uuid::v7()));
        $this->entityManager->persist(new WidgetPreference('patrols', 8, $area));
        $this->entityManager->persist(new WidgetPreference('incidents', 7, $area));
        $this->entityManager->persist(new WidgetPreference('patrols', 7, null));
        $this->entityManager->flush();

        self::assertCount(5, $this->repository()->findAll());
    }

    public function testAnOrgWideLayoutIsSavedResolvedAndResetWithoutAnArea(): void
    {
        $catalog = self::catalog();

        // No row yet: the catalogue's own layout.
        self::assertSame(
            ['tree', 'vacancies'],
            array_column($this->widgets()->resolve($catalog, 7), 'id'),
        );

        $this->widgets()->save($catalog, 7, [
            'order' => ['vacancies', 'tree'],
            'widgets' => ['tree' => ['on' => false, 'cols' => 6]],
        ]);
        $this->entityManager->clear();

        $resolved = $this->widgets()->resolve($catalog, 7);
        self::assertSame(['vacancies', 'tree'], array_column($resolved, 'id'));
        self::assertFalse($resolved[1]['on']);
        self::assertSame(6, $resolved[1]['cols']);
        // Another person's dashboard is untouched by it.
        self::assertSame(['tree', 'vacancies'], array_column($this->widgets()->resolve($catalog, 8), 'id'));

        $this->widgets()->reset($catalog, 7);
        self::assertNull($this->repository()->findOneForUser('departments', 7));
        self::assertSame(['tree', 'vacancies'], array_column($this->widgets()->resolve($catalog, 7), 'id'));
    }

    public function testSavingTwiceUpdatesTheOnePersonsOneRow(): void
    {
        $catalog = self::catalog();

        $this->widgets()->save($catalog, 7, ['order' => ['vacancies', 'tree'], 'widgets' => []]);
        $this->widgets()->save($catalog, 7, ['order' => ['tree', 'vacancies'], 'widgets' => []]);

        self::assertCount(1, $this->repository()->findAll());
        self::assertSame(['tree', 'vacancies'], array_column($this->widgets()->resolve($catalog, 7), 'id'));
    }

    public function testAreaScopedAndOrgWideLayoutsOfTheSameSurfaceDoNotSeeEachOther(): void
    {
        $catalog = self::catalog();
        $area = Uuid::v7();

        $this->widgets()->save($catalog, 7, ['order' => ['vacancies', 'tree'], 'widgets' => []], $area);

        self::assertSame(['vacancies', 'tree'], array_column($this->widgets()->resolve($catalog, 7, $area), 'id'));
        // The org-wide layout of the same surface never learned of it.
        self::assertSame(['tree', 'vacancies'], array_column($this->widgets()->resolve($catalog, 7), 'id'));
    }

    public function testAnAnonymousRequestAlwaysGetsTheCatalogueDefaults(): void
    {
        $catalog = self::catalog();
        $this->widgets()->save($catalog, 7, ['order' => ['vacancies', 'tree'], 'widgets' => []]);

        self::assertSame(['tree', 'vacancies'], array_column($this->widgets()->resolve($catalog, null), 'id'));
    }
}
