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

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\RouterInterface;
use Uhifadhi\Entity\Department;
use Uhifadhi\Enum\TeamRoleEnum;
use Uhifadhi\Factory\AreaOfInterestFactory;
use Uhifadhi\Factory\DepartmentFactory;
use Uhifadhi\Factory\ModuleFactory;
use Uhifadhi\Model\WidgetDom;

/**
 * ONE PRESET COMPONENT, EVERY SURFACE — proved over every library page the app ships rather
 * than asserted once on the surface that happened to be written first.
 *
 * {@see \Uhifadhi\Tests\Unit\WidgetLibraryAssetsTest} pins the OTHER half of the seam: that
 * assets/widgets.js drives the page through exactly the names {@see WidgetDom} declares. That
 * test reads the shipped asset as text and so can say nothing about whether a given page renders
 * them. This one says it, for all of them: every library route below hands the shared component
 * the WHOLE contract, so a surface that migrated onto it and forgot a route — a missing `copy`
 * being the easy one, since built-ins are immutable and copying is the only way out of one —
 * fails HERE instead of leaving a dead button in somebody's browser.
 *
 * A surface added later that does not appear in {@see libraries()} is the one gap this cannot
 * close, so the count is asserted too.
 */
final class WidgetLibrarySeamTest extends AuthenticatedWebTestCase
{
    private ?Department $department = null;

    private ?string $area = null;

    /**
     * Every widget-library page, as a path and the URL prefix its framework routes hang off.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function libraries(): iterable
    {
        yield 'departments' => ['/departments/widgets', '/departments/widgets'];
        yield 'team' => ['/team/widgets', '/team/widgets'];
        yield 'performance board' => ['/departments/performance/widgets', '/departments/performance/widgets'];
        yield 'department record' => ['/departments/{department}/widgets', '/departments/{department}/widgets'];
        yield 'department scorecard' => ['/departments/{department}/performance/widgets', '/departments/{department}/performance/widgets'];
        yield 'zones' => ['/areas/{area}/zones/widgets', '/areas/{area}/zones/widgets'];
        // The COMPOSED surface. Its catalogue is assembled per area rather than
        // declared, and it still hands the component exactly the same contract —
        // which is the point: a surface whose widgets come from three owners is
        // not a special case of the framework.
        yield 'area overview' => ['/areas/{area}/overview/widgets', '/areas/{area}/overview/widgets'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('libraries')]
    public function testEveryLibraryHandsTheComponentTheWholeContract(string $path, string $prefix): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Manager);
        $crawler = $this->open($client, $path);

        self::assertResponseIsSuccessful($path.' does not open.');

        $root = $crawler->filter('['.WidgetDom::ROOT.']');
        self::assertCount(1, $root, $path.' renders no library root.');

        $id = WidgetDom::ID_PLACEHOLDER;
        $prefix = $this->resolve($prefix);
        foreach ([
            WidgetDom::SAVE_URL => '/save',
            WidgetDom::RESET_URL => '/reset',
            WidgetDom::PRESET_URL => '/preset/'.$id,
            // The only door out of an immutable shipped design. A surface without it offers
            // "Make a copy to customize" and then 404s, which is worse than not offering it.
            WidgetDom::PRESET_COPY_URL => '/preset/'.$id.'/copy',
            WidgetDom::PRESETS_URL => '/presets',
            WidgetDom::PRESET_APPLY_URL => '/presets/'.$id.'/apply',
            WidgetDom::PRESET_RENAME_URL => '/presets/'.$id.'/rename',
            WidgetDom::PRESET_DELETE_URL => '/presets/'.$id.'/delete',
        ] as $attribute => $suffix) {
            self::assertSame($prefix.$suffix, $root->attr($attribute), $path.' · '.$attribute);
        }

        // A token, minted for THIS surface, on the very element that carries those URLs.
        self::assertNotSame('', (string) $root->attr(WidgetDom::CSRF_TOKEN), $path.' mints no token.');

        // The catalogue the script previews from, and one cloneable render per widget it names.
        /** @var array{surface: string, widgets: list<array{id: string}>, active: array{kind: string, id: string}} $catalog */
        $catalog = json_decode(
            $crawler->filter('script['.WidgetDom::CATALOG.']')->text(),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        self::assertNotSame('', $catalog['surface']);
        self::assertCount(\count($catalog['widgets']), $crawler->filter('['.WidgetDom::TEMPLATE.']'), $path);

        // EXACTLY ONE ACTIVE PRESET. There is no anonymous layout in this model, so a page
        // showing none — or two — is a page whose strip cannot be read.
        self::assertCount(1, $crawler->filter('.w-preset-active'), $path.' has no single active preset.');
        self::assertContains($catalog['active']['kind'], ['design', 'mine'], $path);
    }

    public function testNoWidgetSurfaceIsMissingFromThisTest(): void
    {
        // THE ONE GAP THE ASSERTIONS ABOVE CANNOT CLOSE: a surface added later, migrated onto the
        // component, and never listed here would be covered by nothing. So the list is checked
        // against the ROUTER — every registered library page (a GET route whose name ends
        // "_widgets") must appear in libraries(), and adding a surface fails until it does.
        self::bootKernel();
        /** @var RouterInterface $router */
        $router = static::getContainer()->get('router');

        $registered = [];
        foreach ($router->getRouteCollection() as $name => $route) {
            // HOST surfaces only. A module bundle ships its own library screen and its own
            // catalogue, and migrating one onto this component is that bundle's release — the
            // patrols module's is still on the retired sectioned model, and asserting the host's
            // contract over it here would only pin a bug in somebody else's repo. Its own suite
            // covers it; this list is every library THIS repo owns.
            if (str_starts_with($name, 'app_') && str_ends_with($name, '_widgets') && \in_array('GET', $route->getMethods(), true)) {
                $registered[] = $route->getPath();
            }
        }
        sort($registered);

        $covered = [];
        foreach (self::libraries() as [$path]) {
            // Back to the route's own shape, so the two lists are the same vocabulary.
            $covered[] = str_replace(['{department}', '{area}'], ['{uuid}', '{uuid}'], $path);
        }
        sort($covered);

        self::assertSame($registered, $covered, 'A widget library exists that this test does not cover.');
    }

    public function testNoLibraryPageStillCarriesItsOwnCopyOfTheComponent(): void
    {
        // The migration's whole point: one component, included. A surface that kept its own
        // markup would still pass the contract above while drifting from every other screen,
        // so the giveaway is asserted directly — the retired sectioned library drew .w-section
        // headings and an on/off state chip, and the component draws neither.
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Manager);

        foreach (self::libraries() as $name => [$path]) {
            $crawler = $this->open($client, $path);
            self::assertResponseIsSuccessful($name);
            self::assertCount(0, $crawler->filter('.w-section'), $name.' still draws the retired sections.');
            self::assertCount(0, $crawler->filter('[data-widget-state]'), $name.' still draws the retired state chip.');
            // The canvas, the picker and the toolbar are the component's own three parts.
            self::assertCount(1, $crawler->filter('.w-canvas'), $name);
            self::assertCount(1, $crawler->filter('[data-picker]'), $name);
            self::assertCount(1, $crawler->filter('.w-previewbar'), $name);
        }
    }

    /** The fixtures the parameterised paths need, created once per request. */
    private function open(KernelBrowser $client, string $path): Crawler
    {
        return $client->request('GET', $this->resolve($path));
    }

    /**
     * A path with `{department}` / `{area}` filled in from a fixture made on first use, so every
     * library is reached the way a person reaches it and none of them is special-cased.
     */
    private function resolve(string $path): string
    {
        if (str_contains($path, '{department}')) {
            $path = str_replace('{department}', (string) $this->department()->getUuidString(), $path);
        }
        if (str_contains($path, '{area}')) {
            $path = str_replace('{area}', $this->area(), $path);
        }

        return $path;
    }

    private function department(): Department
    {
        return $this->department ??= DepartmentFactory::createOne([
            'name' => 'Ecology',
            'modules' => [ModuleFactory::createOne(['slug' => 'patrols', 'name' => 'Patrols'])],
        ]);
    }

    private function area(): string
    {
        return $this->area ??= (string) AreaOfInterestFactory::createOne()->getUuidString();
    }
}
