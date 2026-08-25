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
use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Entity\Department;
use Uhifadhi\Factory\DepartmentFactory;
use Uhifadhi\Factory\ModuleFactory;

/**
 * The org-wide comparative board.
 *
 * The behaviour under test is mostly what the board REFUSES to do: hide a department that has
 * nothing to show, print a zero where nothing was measured, collapse two different emptinesses
 * into one, or read as a league table. No module provider is installed in the host's kernel, so
 * every cell here is the honest-empty case — which is precisely the case a board gets wrong, and
 * precisely what a fresh install shows.
 *
 * Every control on this page is a QUERY PARAMETER — period, search, bucket, sort, selection — so
 * all of it is exercised here with no script at all, exactly as a person without JavaScript uses
 * it. The arithmetic behind them is pinned in {@see \Uhifadhi\Tests\Unit\PerformanceBoardViewTest}.
 */
final class PerformanceBoardTest extends AuthenticatedWebTestCase
{
    public function testEveryDepartmentIsARowIncludingTheOnesWithNothingToShow(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        self::org();

        $crawler = $client->request('GET', '/departments/performance');

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('[data-w="board"] tbody tr.perf-row');
        self::assertCount(3, $rows);
        self::assertSame(
            ['Community Development', 'Ecology', 'Protection Service'],
            $rows->each(static fn (Crawler $row): string => trim($row->filter('.dept b')->text())),
        );

        // The design's row identity: a code mark and what the row is made of.
        self::assertSame('CD', trim($rows->first()->filter('.dept .mk')->text()));
        self::assertStringContainsString('no module attached', $rows->first()->filter('.dept div span')->text());
        self::assertStringContainsString('1 module · no area', $rows->eq(1)->filter('.dept div span')->text());
    }

    /**
     * A row selects INTO the drill-in below, and does it with a real link — which is how the
     * selection survives a reload, a copied URL and a person who does not use a mouse.
     */
    public function testEveryRowIsSelectableAndKeyboardReachable(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $ecology = self::org();

        $row = $client->request('GET', '/departments/performance')
            ->filter('[data-w="board"] tbody tr.perf-row')->eq(1);

        self::assertSame($ecology->getUuidString(), $row->attr('data-uuid'));

        $select = $row->filter('a.dept');
        self::assertStringContainsString('selected='.$ecology->getUuidString(), (string) $select->attr('href'));
        self::assertStringEndsWith('#drill', (string) $select->attr('href'), 'the selection lands on the panel it changed');
        self::assertSame('Show Ecology in the drill-in below', $select->attr('aria-label'));
    }

    public function testAnUnmeasuredCellIsALabelledSlotAndNeverAZero(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        self::org();

        $crawler = $client->request('GET', '/departments/performance');

        // No provider is installed, so there are no KPI columns at all — and the board says so
        // rather than rendering a grid of zeros.
        self::assertCount(0, $crawler->filter('[data-w="board"] thead th[data-sort]:not([data-sort="name"]):not([data-sort="rank"])'));
        self::assertStringContainsString('no columns', $crawler->filter('[data-w="board"]')->text());
        self::assertStringNotContainsString('>0<', $crawler->filter('[data-w="board"] tbody')->html());
    }

    public function testTheBoardSaysItIsNotALeagueTableAndCarriesTheDerivnote(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        self::org();

        $crawler = $client->request('GET', '/departments/performance');
        $board = $crawler->filter('[data-w="board"]')->text();

        self::assertStringContainsString('smaller, not worse', $board);
        // The design's singular: the columns are the KPIs of the module they SHARE.
        self::assertStringContainsString('the KPIs of the module they share', $board);
        self::assertStringContainsString('period over period', $board);

        $note = $crawler->filter('.derivnote')->text();
        self::assertStringContainsString('A department has no numbers of its own', $note);
        self::assertStringContainsString('this page reads, it never fences', $note);
    }

    public function testEveryDepartmentNameOnTheBoardIsALinkIntoItsRecord(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $ecology = self::org();

        $crawler = $client->request('GET', '/departments/performance');
        $link = $crawler->filter('[data-w="board"] tbody a.open-btn')->reduce(
            static fn (Crawler $a): bool => str_contains((string) $a->attr('href'), (string) $ecology->getUuidString()),
        );

        self::assertCount(1, $link);

        // And following it really lands on that record — the board is where you notice something,
        // the record is where you look into it.
        $client->click($link->link());
        self::assertResponseIsSuccessful();
    }

    // ---- the period -------------------------------------------------------------------------

    public function testThePeriodIsAQueryParameterAndDrivesTheWholePage(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        self::org();

        $crawler = $client->request('GET', '/departments/performance?period=quarter');

        self::assertResponseIsSuccessful();
        $on = $crawler->filter('.periodpick a.on');
        self::assertCount(1, $on, 'exactly one period is current');
        self::assertSame('Quarter', trim($on->text()));
        self::assertSame('true', $on->attr('aria-pressed'));
        self::assertMatchesRegularExpression('/^Q[1-4] \d{4}$/', trim($crawler->filter('[data-stance-period]')->text()));

        // The control is real links, so it works with no script at all.
        self::assertCount(4, $crawler->filter('.periodpick a[href]'));
    }

    public function testAnUnknownPeriodIsTheMonthAndNotAnError(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        self::org();

        $crawler = $client->request('GET', '/departments/performance?period=decade');

        self::assertResponseIsSuccessful();
        self::assertSame('Month', trim($crawler->filter('.periodpick a.on')->text()));
    }

    public function testThePageHeaderCarriesTheBoardsOwnActions(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        self::org();

        $crawler = $client->request('GET', '/departments/performance?period=year');

        self::assertCount(1, $crawler->filter('.pgact.perf-pgact'));
        $export = $crawler->filter('a.perf-act');
        self::assertCount(1, $export);
        self::assertStringContainsString('Export board', $export->text());
        // The export is stated for the period being read, like every other number on the page.
        self::assertStringContainsString('period=year', (string) $export->attr('href'));
    }

    public function testTheStanceStatesTheSizeOfTheThingBeingCompared(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        self::org();

        $stance = $client->request('GET', '/departments/performance')->filter('[data-stance] div');

        self::assertCount(5, $stance);
        self::assertSame(
            ['Departments', 'Reporting', 'Module live', 'Areas', 'Period'],
            $stance->each(static fn (Crawler $item): string => trim($item->filter('span')->text())),
        );
        self::assertSame('3', trim($stance->first()->filter('b')->text()));
    }

    // ---- search, buckets, sort --------------------------------------------------------------

    public function testTheThreeEmptinessesAreDifferentAndTheBoardSaysWhich(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        self::org();

        $crawler = $client->request('GET', '/departments/performance');
        $filters = $crawler->filter('.perf-filters a');

        self::assertSame(
            ['All 3', 'Reporting 0', 'Awaiting a module 2', 'No module attached 1'],
            $filters->each(static fn (Crawler $a): string => preg_replace('/\s+/', ' ', trim($a->text())) ?? ''),
        );

        $awaiting = $client->request('GET', '/departments/performance?show=awaiting');
        self::assertCount(2, $awaiting->filter('[data-w="board"] tbody tr.perf-row'));

        $unattached = $client->request('GET', '/departments/performance?show=unattached');
        $rows = $unattached->filter('[data-w="board"] tbody tr.perf-row');
        self::assertCount(1, $rows);
        self::assertSame('Community Development', trim($rows->filter('.dept b')->text()));
    }

    public function testSearchIsServerSideAndSaysSoWhenNothingMatches(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        self::org();

        $found = $client->request('GET', '/departments/performance?q=patrols');
        self::assertCount(2, $found->filter('[data-w="board"] tbody tr.perf-row'), 'searched by an attached module');
        // The counts follow the search.
        self::assertSame('2', trim($found->filter('.perf-filters a')->first()->filter('b')->text()));

        $none = $client->request('GET', '/departments/performance?q=zzzz');
        self::assertCount(0, $none->filter('[data-w="board"] tbody tr.perf-row'));
        $empty = $none->filter('.perf-empty');
        self::assertCount(1, $empty);
        self::assertNull($empty->attr('hidden'), 'the empty line is shown, not left hidden');
        self::assertStringContainsString('No department matches that search.', $empty->text());
    }

    public function testEveryColumnHeaderSortsAndSaysWhichWayItIsSorted(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        self::org();

        $crawler = $client->request('GET', '/departments/performance?sort=name&dir=desc');

        $head = $crawler->filter('[data-w="board"] thead th[data-sort="name"]');
        self::assertSame('descending', $head->attr('aria-sort'));
        self::assertCount(1, $head->filter('i.sortmark'));
        // The header sorts on the server, so it is a link — focusable and followable without JS.
        self::assertStringContainsString('sort=name', (string) $head->filter('a')->attr('href'));
        self::assertStringContainsString('dir=asc', (string) $head->filter('a')->attr('href'), 'clicking it again reverses');

        self::assertSame(
            ['Protection Service', 'Ecology', 'Community Development'],
            $crawler->filter('[data-w="board"] tbody .dept b')->each(static fn (Crawler $b): string => trim($b->text())),
        );

        // The rank column the design puts second, with its own header.
        self::assertCount(1, $crawler->filter('[data-w="board"] thead th[data-sort="rank"]'));
        self::assertSame('sort: department ↓', trim($crawler->filter('.perf-sortnote .chip')->text()));
    }

    // ---- the panels -------------------------------------------------------------------------

    public function testTheRankShiftPanelIsAShortListAboutMovement(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        self::org();

        $shifts = $client->request('GET', '/departments/performance')->filter('[data-w="shifts"]');

        $items = $shifts->filter('.exc li');
        self::assertLessThanOrEqual(4, $items->count(), 'a fixed short list, never one line per cell');
        self::assertStringContainsString('no row to fill', $shifts->text());
        self::assertStringContainsString('one has no module at all', $shifts->text());
    }

    public function testModuleCoverageShowsWhoSharesWhatAndWhichOfItIsLive(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        self::org();

        $coverage = $client->request('GET', '/departments/performance')->filter('[data-w="coverage"]');

        $rows = $coverage->filter('tbody tr.perf-row');
        self::assertCount(3, $rows);
        self::assertNotNull($rows->first()->attr('data-uuid'));

        // Patrols is claimed by two departments — the chip says how many, not just "shared".
        $shared = $coverage->filter('.dchip.shared')->first();
        self::assertStringContainsString('2', trim($shared->filter('i')->text()));
        // Attached but computing nothing yet is its own marker.
        self::assertGreaterThan(0, $coverage->filter('.dchip.soon')->count());
        // And a department with nothing attached says "none" rather than showing a blank.
        self::assertStringContainsString('none', $coverage->filter('.dchip.ghost')->text());
        self::assertStringContainsString('—', $coverage->filter('tbody .num')->first()->text());
    }

    public function testTheDrillInIsOneSelectedDepartmentAndNeverAFence(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $ecology = self::org();

        $drill = $client->request('GET', '/departments/performance?selected='.$ecology->getUuidString())
            ->filter('[data-w="drill"]');

        // ONE department, not a stack of them.
        self::assertCount(1, $drill->filter('.drillhead'));
        self::assertSame('Ecology', trim($drill->filter('.drillhead .dept b')->text()));
        self::assertStringContainsString('Ecology', $drill->filter('[data-drill-src]')->text());
        self::assertSame('polite', $drill->filter('[data-drill]')->attr('aria-live'));
        self::assertStringContainsString('The board never drills into a fence', $drill->text());
    }

    public function testWithNoSelectionTheDrillInPicksOneAndSaysItDid(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        self::org();

        $drill = $client->request('GET', '/departments/performance')->filter('[data-w="drill"]');

        self::assertCount(1, $drill->filter('.drillhead'));
        self::assertStringContainsString('selected from the board above', $drill->filter('.drillhead .dept div span')->text());
    }

    /**
     * THE designed empty state — the one a fresh install actually shows, and the thing the whole
     * board exists to expose. It names WHICH emptiness, in the design's own grammar.
     */
    public function testTheDrillInNamesWhichEmptinessItIsLookingAt(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $ecology = self::org();

        $attached = $client->request('GET', '/departments/performance?selected='.$ecology->getUuidString())
            ->filter('[data-w="drill"] .blankstate')->text();

        self::assertStringContainsString('Ecology has no KPI to show for this period.', $attached);
        self::assertStringContainsString('Its 1 attached module record', $attached);
        self::assertStringContainsString('none of them computes a KPI yet', $attached);
        self::assertStringContainsString('nothing here needs configuring', $attached);
        self::assertStringContainsString('An empty row is not a zero', $attached);

        $bare = self::findDepartment($client, 'Community Development');
        $unattached = $client->request('GET', '/departments/performance?selected='.$bare)
            ->filter('[data-w="drill"] .blankstate')->text();

        self::assertStringContainsString('No module is attached to it at all', $unattached);
    }

    public function testTheDrillInStatesTheGoalsAndWhereTheDepartmentRuns(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $ecology = self::org();

        $drill = $client->request('GET', '/departments/performance?selected='.$ecology->getUuidString())
            ->filter('[data-w="drill"]');

        self::assertStringContainsString('Goals this department declared', $drill->text());
        self::assertStringContainsString('None declared for this period.', $drill->text());

        $foot = $drill->filter('.drillfoot');
        self::assertStringContainsString('Where it runs', $foot->text());
        // Ecology attaches a module but no area has it switched on — a different sentence from
        // "no module, so no area", which is what a department with nothing attached gets.
        self::assertStringContainsString('nowhere yet — no area has its modules switched on', $foot->text());
        self::assertStringContainsString('0 positions · 0 people', $foot->filter('.drillmeta')->text());
        self::assertStringContainsString('Open the department', $foot->filter('a.open-btn')->text());
    }

    public function testAnUnknownSelectionFallsBackRatherThanFailing(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        self::org();

        $client->request('GET', '/departments/performance?selected=not-a-uuid');

        self::assertResponseIsSuccessful();
    }

    // ---- the board with no departments at all ------------------------------------------------

    public function testWithNoDepartmentAtAllEveryPanelStillSaysSomething(): void
    {
        $client = static::createClient();
        $this->loginAs($client);

        $crawler = $client->request('GET', '/departments/performance');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No department exists yet', $crawler->filter('[data-w="board"]')->text());
        self::assertStringContainsString('No department exists yet', $crawler->filter('[data-w="shifts"]')->text());
        self::assertStringContainsString('No department exists yet', $crawler->filter('[data-w="coverage"]')->text());
        self::assertStringContainsString('No department exists yet', $crawler->filter('[data-w="drill"]')->text());
    }

    public function testTheDepartmentsIndexOffersTheBoard(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        self::org();

        $link = $client->request('GET', '/departments')->filter('.pghead a')->reduce(
            static fn (Crawler $a): bool => str_contains($a->text(), 'Performance board'),
        );

        self::assertCount(1, $link);
        self::assertSame('/departments/performance', $link->attr('href'));
    }

    public function testTheBoardsWidgetLibraryOpensAndOffersItsDesigns(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        self::org();

        $crawler = $client->request('GET', '/departments/performance/widgets');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('.w-preset')->count());
        self::assertGreaterThan(0, $crawler->filter('.w-card')->count());
    }

    /** The uuid of a department by name, read off the board itself. */
    private static function findDepartment(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $name): string
    {
        $row = $client->request('GET', '/departments/performance')
            ->filter('[data-w="board"] tbody tr.perf-row')
            ->reduce(static fn (Crawler $tr): bool => $name === trim($tr->filter('.dept b')->text()));

        return (string) $row->attr('data-uuid');
    }

    /** Three departments: one sharing a module, one with a module of its own, one with none. */
    private static function org(): Department
    {
        $patrols = ModuleFactory::createOne(['slug' => 'patrols', 'name' => 'Patrols']);

        $ecology = DepartmentFactory::createOne(['name' => 'Ecology']);
        $protection = DepartmentFactory::createOne(['name' => 'Protection Service']);
        DepartmentFactory::createOne(['name' => 'Community Development']);

        $ecology->addModule($patrols);
        $protection->addModule($patrols);
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        \assert($em instanceof EntityManagerInterface);
        $em->flush();

        return $ecology;
    }
}
