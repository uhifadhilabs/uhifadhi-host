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

namespace Uhifadhi\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * WHAT THE HOST STILL OWES THE MAP PLATFORM, now that the platform is a bundle.
 *
 * The basemap seam itself — the session cache, the three-state reader, the
 * provider payload — left this repository with the asset, and its test went with
 * it: a defect of that file must fail where someone would edit it
 * (uhifadhilabs/map-module, tests/Unit/Assets/BasemapSeamAssetsTest).
 *
 * What CANNOT move is the obligation on the host's OWN map controllers, because
 * those controllers are here. Two of them, and both are the kind of defect no
 * HTTP test can see:
 *
 *  1. A controller must take its imagery FROM the seam. The day one builds its
 *     own satellite layer, that map draws a different ground from every other
 *     map in the product, and the deployment's configured provider is silently
 *     ignored on exactly one screen.
 *
 *  2. A controller that mounts the platform chrome must mark its Leaflet
 *     container. The chrome is a SIBLING of that container, and Leaflet numbers
 *     its own panes 200–800; without `.map-chrome-host` and the
 *     `isolation: isolate` it carries, every pane outranks the controls and they
 *     vanish the moment the first tile arrives.
 *
 * The second is a rough edge worth naming rather than hiding: mountMapChrome()
 * does not add that class itself, so the obligation is on each caller — in this
 * repository AND in every module bundle that draws a plate. This sweep can only
 * hold the callers it can see.
 */
final class MapControllerSeamTest extends TestCase
{
    /** @return list<string> */
    private static function controllers(): array
    {
        $files = glob(\dirname(__DIR__, 2).'/assets/controllers/*.js') ?: [];
        self::assertNotEmpty($files);

        return $files;
    }

    public function testEveryMapControllerTakesItsBasemapsFromTheSeam(): void
    {
        $mapControllers = [];
        foreach (self::controllers() as $file) {
            $source = (string) file_get_contents($file);
            if (!str_contains($source, 'satelliteLayer')) {
                continue;
            }
            $mapControllers[] = basename($file);
            self::assertStringContainsString(
                "from 'uhifadhi/basemaps'",
                $source,
                \sprintf('%s must take satellite from the platform seam, never build its own.', basename($file)),
            );
            self::assertStringNotContainsString(
                'tile.googleapis.com',
                $source,
                \sprintf('%s must not know Google\'s endpoint: that is the seam\'s business.', basename($file)),
            );
            self::assertStringNotContainsString(
                'arcgisonline.com',
                $source,
                \sprintf('%s must not name an imagery host: which provider draws is configuration.', basename($file)),
            );
        }

        self::assertNotEmpty($mapControllers, 'No controller draws satellite — the sweep proved nothing.');
    }

    public function testEveryMapThatMountsTheChromeMarksItsLeafletContainer(): void
    {
        $mounting = [];
        foreach (self::controllers() as $file) {
            $source = (string) file_get_contents($file);
            if (!str_contains($source, 'mountMapChrome(')) {
                continue;
            }
            $mounting[] = basename($file);
            self::assertStringContainsString(
                "classList.add('map-chrome-host')",
                $source,
                \sprintf('%s mounts the platform chrome but never marks its Leaflet container.', basename($file)),
            );
        }

        self::assertNotEmpty($mounting, 'No controller mounts the platform map chrome — the sweep proved nothing.');
    }

    /**
     * The three shared modules are named by BARE SPECIFIER, never by path. That
     * is what let the platform move out of this repository without touching a
     * single importer, and it is what will let it move again.
     */
    public function testNoControllerReachesIntoTheBundleByPath(): void
    {
        foreach (self::controllers() as $file) {
            self::assertStringNotContainsString(
                '@uhifadhilabs/map-module',
                (string) file_get_contents($file),
                \sprintf('%s must import uhifadhi/basemaps et al by name; the path is importmap.php\'s business.', basename($file)),
            );
        }
    }
}
