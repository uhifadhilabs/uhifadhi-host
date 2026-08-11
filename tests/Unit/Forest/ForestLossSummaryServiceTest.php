<?php

declare(strict_types=1);

namespace App\Tests\Unit\Forest;

use App\Forest\Entity\ForestLossYear;
use App\Forest\Service\ForestLossSummaryService;
use App\Forest\Service\LossYearPaletteService;
use PHPUnit\Framework\TestCase;

final class ForestLossSummaryServiceTest extends TestCase
{
    public function testItSummarisesTheSeriesAndIgnores2001ForTheWorstYear(): void
    {
        $summary = $this->service()->summarize([
            $this->row(2001, 999.0), // baseline artifact — must not win "worst year"
            $this->row(2010, 185.0),
            $this->row(2013, 186.0),
        ]);

        self::assertSame(999.0 + 185.0 + 186.0, $summary['totalHa']);
        self::assertSame(2001, $summary['yearFrom']);
        self::assertSame(2013, $summary['yearTo']);
        self::assertSame(999.0, $summary['maxHa']);
        // 2001 is excluded, so 2013 (186) is the worst real year, not 2001 (999).
        self::assertSame(2013, $summary['worstYear']);
        self::assertSame(186.0, $summary['worstHa']);

        // Each entry is coloured by the shared ramp.
        self::assertCount(3, $summary['lossByYear']);
        self::assertStringStartsWith('rgb(', $summary['lossByYear'][0]['color']);
    }

    public function testAnEmptySeriesReportsNoYears(): void
    {
        $summary = $this->service()->summarize([]);

        self::assertSame([], $summary['lossByYear']);
        self::assertSame(0.0, $summary['totalHa']);
        self::assertNull($summary['yearFrom']);
        self::assertNull($summary['worstYear']);
    }

    private function service(): ForestLossSummaryService
    {
        // forArea() is the only method touching the repo; summarize() is pure, so
        // the repo is never called here and can be a throwaway.
        return new ForestLossSummaryService(
            $this->createStub(\App\Forest\Repository\ForestLossYearRepository::class),
            new LossYearPaletteService(),
        );
    }

    private function row(int $year, float $ha): ForestLossYear
    {
        return (new ForestLossYear())->setYear($year)->setAreaHa($ha);
    }
}
