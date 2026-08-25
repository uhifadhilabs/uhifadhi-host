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

namespace Uhifadhi\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Entity\Department;
use Uhifadhi\Entity\DepartmentGoal;
use Uhifadhi\Enum\TeamRoleEnum;
use Uhifadhi\Factory\DepartmentFactory;
use Uhifadhi\Factory\ModuleFactory;
use Uhifadhi\Factory\PositionFactory;
use Uhifadhi\Factory\UserFactory;
use Uhifadhi\Repository\DepartmentGoalRepository;

/**
 * One department's own page: the tabbed record, its two widget surfaces, and the goals CRUD that
 * gives the performance surfaces something to be measured against.
 *
 * The KPI arithmetic is NOT tested here — no module is installed in the host's test kernel, which
 * is itself the case worth asserting: with nothing attached the page must draw dashed labelled
 * slots and never zeros. The slice arithmetic belongs to the module that owns the rows and is
 * tested there.
 */
final class DepartmentDetailTest extends AuthenticatedWebTestCase
{
    public function testTheRecordOpensAtItsUuidWithAllFourTabs(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $department = self::ecology();

        $crawler = $client->request('GET', $this->show($department));

        self::assertResponseIsSuccessful();
        self::assertSame('Ecology', $crawler->filter('h1.pg')->text());

        $tabs = $crawler->filter('.dp-tabs a');
        self::assertSame(
            ['Overview', 'Performance', 'People & Positions', 'Settings'],
            $tabs->each(static fn (Crawler $tab): string => trim($tab->text())),
        );
        // Real anchors: with the script off the strip is a jump list and every panel is present.
        self::assertCount(4, $crawler->filter('.dp-panel'));
    }

    public function testASequentialIdIsNotAnAddress(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        self::ecology();

        $client->request('GET', '/departments/1');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheOverviewGridRendersTheDefaultWidgetsAgainstRealEntities(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $department = self::ecology();

        $crawler = $client->request('GET', $this->show($department));
        $overview = $crawler->filter('#overview');

        // The identity plate counts what the record actually holds: 1 module, 1 position, 1 person.
        $stats = $overview->filter('[data-w="identity"] .railstat b');
        self::assertSame(['1', '1', '1'], $stats->each(static fn (Crawler $n): string => $n->text()));

        // A module attached to two departments is marked shared, from the real join.
        self::assertStringContainsString('SHARED', $overview->filter('[data-w="modules"]')->text());

        // Membership is via position, and says so.
        self::assertStringContainsString('via position', $overview->filter('[data-w="members"]')->text());
    }

    public function testWithNoModuleReportingTheScorecardDrawsDashedSlotsAndNeverZeros(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $department = self::ecology();

        $crawler = $client->request('GET', $this->show($department));
        $scorecard = $crawler->filter('[data-w="scorecard"]');

        // Patrols IS attached, but no provider is installed in the host's kernel — so the plate
        // exists, is dashed, and says what is missing.
        $slots = $scorecard->filter('.dp-slot');
        self::assertGreaterThanOrEqual(1, $slots->count());
        self::assertStringContainsString('—', $slots->first()->filter('.kpi-fig b')->text());
        self::assertStringNotContainsString('0', $slots->first()->filter('.kpi-fig b')->text());
        self::assertStringContainsString('not a zero', $scorecard->text());
    }

    public function testEveryPerformanceSurfaceCarriesTheDerivnote(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $department = self::ecology();

        $note = $client->request('GET', $this->show($department))->filter('[data-w="provenance"]')->text();

        self::assertStringContainsString('A department has no numbers of its own', $note);
        self::assertStringContainsString('summed over the areas those modules are switched on in', $note);
        self::assertStringContainsString('position sits in this department', $note);
        self::assertStringContainsString('this page reads, it never fences', $note);
    }

    public function testAManagerDeclaresAGoalAndItIsScoredAsAwaitingAModule(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Manager);
        $department = self::ecology();

        $client->request('POST', $this->show($department).'/goals', [
            '_token' => $this->token($client, $department),
            'statement' => 'Coverage at least 60% each month',
            'kpiRef' => 'coverage',
            'targetValue' => '60',
            'targetUnit' => '%',
            'period' => 'month',
        ]);

        self::assertResponseRedirects();
        $crawler = $client->followRedirect();

        $goals = self::goalsOf($department);
        self::assertCount(1, $goals);
        self::assertSame('coverage', $goals[0]->getKpiRef());
        self::assertSame(60.0, $goals[0]->getTargetValue());

        // Nothing installed measures coverage, so the honest state is AWAITING — not 0% attained.
        self::assertSame(DepartmentGoal::AWAITING, $goals[0]->state(null));
        self::assertStringContainsString('Awaiting module', $crawler->filter('#settings')->text());
    }

    public function testAGoalNeedsAStatementAndAKpi(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Manager);
        $department = self::ecology();

        $client->request('POST', $this->show($department).'/goals', [
            '_token' => $this->token($client, $department),
            'statement' => '  ',
            'kpiRef' => 'coverage',
        ]);

        self::assertResponseRedirects();
        self::assertCount(0, self::goalsOf($department));
    }

    public function testAGoalIsWithdrawnAndOnlyTheDeclarationGoes(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Manager);
        $department = self::ecology();
        $token = $this->token($client, $department);

        $client->request('POST', $this->show($department).'/goals', [
            '_token' => $token,
            'statement' => 'Coverage at least 60% each month',
            'kpiRef' => 'coverage',
            'targetValue' => '60',
            'targetUnit' => '%',
        ]);
        $goals = self::goalsOf($department);

        $client->request('POST', $this->show($department).'/goals/'.$goals[0]->getUuidString().'/delete', ['_token' => $token]);

        self::assertResponseRedirects();
        self::assertCount(0, self::goalsOf($department));
        // The department itself is untouched: withdrawing a target deletes no record but its own.
        self::assertNotNull($department->getId());
    }

    public function testDeclaringAGoalIsAManagersWriteAndStaffAreNotEvenShownTheForm(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);
        $department = self::ecology();

        // READING is for everyone: a department is a lens, and a lens nobody may look through
        // explains nothing. So the page opens, and only the write is missing from it.
        $crawler = $client->request('GET', $this->show($department));
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.dp-goalform'));
        self::assertStringContainsString('Declaring a goal is a manager', $crawler->filter('[data-w="goal-declare"]')->text());

        // And the endpoint refuses it too — the chrome and the route never disagree. #[IsGranted]
        // runs before the controller body, so the tier is what answers here; the token value is
        // irrelevant and deliberately not a real one.
        $client->request('POST', $this->show($department).'/goals', [
            '_token' => 'not-this-person-s-token',
            'statement' => 'Coverage at least 60%',
            'kpiRef' => 'coverage',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, self::goalsOf($department));
    }

    public function testBothWidgetLibrariesOpenAndOfferTheirDesigns(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $department = self::ecology();

        foreach (['/widgets', '/performance/widgets'] as $path) {
            $crawler = $client->request('GET', $this->show($department).$path);

            self::assertResponseIsSuccessful();
            self::assertGreaterThan(0, $crawler->filter('.w-preset')->count(), $path.' offers no design to start from.');
            self::assertGreaterThan(0, $crawler->filter('.w-card')->count(), $path.' shows no widget.');
            // The library must say that arranging here follows you to every department.
            self::assertStringContainsString('every department', $crawler->filter('.w-libintro')->text());
        }
    }

    /** Ecology, sharing Patrols with Protection Service, with one position and one person in it. */
    private static function ecology(): Department
    {
        $patrols = ModuleFactory::createOne(['slug' => 'patrols', 'name' => 'Patrols']);

        $ecology = DepartmentFactory::createOne(['name' => 'Ecology']);
        $protection = DepartmentFactory::createOne(['name' => 'Protection Service']);
        // Attached to BOTH: one module, two lenses — what the "shared" markers are drawn from.
        $ecology->addModule($patrols);
        $protection->addModule($patrols);

        $analyst = PositionFactory::createOne(['name' => 'Analyst', 'department' => $ecology]);
        UserFactory::createOne(['position' => $analyst]);
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        \assert($em instanceof EntityManagerInterface);
        $em->flush();

        return $ecology;
    }

    /**
     * The goal repository, typed — `getContainer()->get()` answers `object`, and a test that
     * chains off `object` is a test phpstan cannot check.
     *
     * @return list<DepartmentGoal>
     */
    private static function goalsOf(Department $department): array
    {
        $repository = static::getContainer()->get(DepartmentGoalRepository::class);
        \assert($repository instanceof DepartmentGoalRepository);

        return $repository->forDepartment($department);
    }

    /**
     * The management token, read off the page that would submit it — a token fetched from the
     * container has no session behind it, and a test that mints its own proves less than one that
     * uses the form's.
     */
    private function token(KernelBrowser $client, Department $department): string
    {
        $crawler = $client->request('GET', $this->show($department));

        return (string) $crawler->filter('#settings input[name="_token"]')->first()->attr('value');
    }

    private function show(Department $department): string
    {
        return '/departments/'.$department->getUuidString();
    }
}
