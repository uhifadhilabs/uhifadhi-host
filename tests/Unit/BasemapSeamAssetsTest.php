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
 * THE PLATFORM'S BASEMAP SEAM — assets/google_tiles.js.
 *
 * Satellite is Google's Map Tiles API with the keyless Esri imagery underneath
 * it, and the fallback has always worked: a map is never blank. What did not
 * work is how often it asked. Google's Map Tiles API refuses satellite outright
 * for an EEA-billed account —
 *
 *     403 · "satellite tiles and 3D tiles are not available for your account
 *            and region"
 *
 * — which is a settled fact about the account, not a blip. The seam cached only
 * SUCCESS, so that settled refusal was never remembered: every map on a page
 * asked again, and every remount asked again after that. A two-map page fired
 * two createSession calls, and the console filled with 403s for an outcome the
 * code had already accepted.
 *
 * A refusal is an answer. These assertions read the shipped asset as TEXT —
 * there is no JS runner in this project, and this is a defect no HTTP test can
 * see, exactly like the CSRF seam in {@see WidgetLibraryAssetsTest}.
 */
final class BasemapSeamAssetsTest extends TestCase
{
    private static function basemapsJs(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2).'/assets/google_tiles.js');
    }

    public function testTheSessionIsAskedForAtMostOncePerDocument(): void
    {
        $js = self::basemapsJs();

        // One shared attempt, held at module scope. Eight widgets on a dashboard
        // are eight satelliteLayer() calls; they must produce one request.
        self::assertMatchesRegularExpression(
            '/^let pendingSession = null;/m',
            $js,
            'The seam must hold its one attempt at module scope so every map on the page shares it.',
        );
        self::assertStringContainsString(
            'pendingSession ??=',
            $js,
            'The first caller starts the attempt and every later one awaits the same promise.',
        );
        // Only createSession may talk to the network from here.
        self::assertSame(
            1,
            substr_count($js, 'fetch('),
            'The basemap seam makes exactly one kind of network call: createSession.',
        );
    }

    public function testARefusalIsRememberedSoItIsNotAskedAgain(): void
    {
        $js = self::basemapsJs();

        // The negative answer is written to the same store as the positive one.
        // Without this line the 403 is re-earned on every mount.
        self::assertStringContainsString(
            'cacheSession(null,',
            $js,
            'A refused session must be cached: Google saying no is an answer, not a missing one.',
        );
        self::assertMatchesRegularExpression(
            '/const NEGATIVE_TTL_MS = /',
            $js,
            'A remembered refusal needs its own lifetime, so an account that gains access recovers.',
        );
    }

    public function testNothingKnownIsDistinctFromAskedAndRefused(): void
    {
        $js = self::basemapsJs();

        // The cache reader has three answers, and collapsing two of them is how
        // a remembered refusal would silently become another request.
        self::assertStringContainsString(
            'return undefined; // nothing known',
            $js,
            'The reader must say "nothing known" distinctly from "asked, and refused" (null).',
        );
        self::assertStringContainsString(
            'undefined !== cached',
            $js,
            'A cached refusal (null) must short-circuit the request just as a cached token does.',
        );
    }

    /**
     * Every map in the product takes its ground from this one seam — the host's
     * area and overview maps and each module's plates alike.
     */
    public function testEveryMapControllerTakesItsBasemapsFromTheSeam(): void
    {
        $controllers = glob(\dirname(__DIR__, 2).'/assets/controllers/*.js') ?: [];
        self::assertNotEmpty($controllers);

        $mapControllers = [];
        foreach ($controllers as $file) {
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
        }

        self::assertNotEmpty($mapControllers, 'No controller draws satellite — the sweep proved nothing.');
    }
}
