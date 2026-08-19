<?php

declare(strict_types=1);

namespace Uhifadhi\Tests\Unit\Forest;

use Uhifadhi\Forest\Service\LossYearPaletteService;
use PHPUnit\Framework\TestCase;

/**
 * The pure summary over the module's (year, ha, cumulative_ha) series rows — totals, span, worst
 * year (which must ignore 2001, the known Hansen baseline artifact), and the per-year ramp colour.
 */
final class ForestLossSummaryServiceTest extends TestCase
{
    public function testSummarizesTotalsSpanAndWorstYearIgnoring2001(): void
    {
        $summary = $this->summarize([
            [2001, 1657.0, 1657.0],
            [2013, 186.0, 1843.0],
            [2014, 120.0, 1963.0],
        ]);

        self::assertSame(1963.0, $summary['totalHa']);
        self::assertSame(1657.0, $summary['maxHa']);
        self::assertSame(2001, $summary['yearFrom']);
        self::assertSame(2014, $summary['yearTo']);
        // 2001 has the biggest bar, but the worst year skips the baseline artifact.
        self::assertSame(2013, $summary['worstYear']);
        self::assertSame(186.0, $summary['worstHa']);
        self::assertSame([2001, 2013, 2014], array_column($summary['lossByYear'], 'year'));
        self::assertNotSame('', $summary['lossByYear'][0]['color']);
    }

    public function testAnEmptySeriesSummarizesToZeroesAndNulls(): void
    {
        $summary = $this->summarize([]);

        self::assertSame(0.0, $summary['totalHa']);
        self::assertNull($summary['yearFrom']);
        self::assertNull($summary['worstYear']);
        self::assertSame([], $summary['lossByYear']);
    }

    /**
     * @param list<list<scalar|null>> $rows
     *
     * @return array<string, mixed>
     */
    private function summarize(array $rows): array
    {
        // summarize() is pure — only forArea() touches the dataset store, so the service can be
        // built with a stubbed repository-free path by calling the pure method directly.
        $service = new \ReflectionClass(\Uhifadhi\Forest\Service\ForestLossSummaryService::class);
        $instance = $service->newInstanceWithoutConstructor();
        $palette = $service->getProperty('palette');
        $palette->setValue($instance, new LossYearPaletteService());

        return $instance->summarize($rows);
    }
}
