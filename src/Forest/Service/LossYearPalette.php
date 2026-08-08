<?php

declare(strict_types=1);

namespace App\Forest\Service;

/**
 * The Hansen YlOrRd year ramp (2001–2023) — MUST stay identical to the stops in
 * assets/controllers/map_controller.js, so the panel's chart bars match the
 * polygons on the map. Guarded by LossYearPaletteTest.
 */
final class LossYearPalette
{
    /** @var list<array{int, array{int, int, int}}> */
    private const STOPS = [
        [2001, [0xFF, 0xFF, 0xB2]],
        [2008, [0xFE, 0xCC, 0x5C]],
        [2014, [0xFD, 0x8D, 0x3C]],
        [2019, [0xF0, 0x3B, 0x20]],
        [2023, [0xBD, 0x00, 0x26]],
    ];

    public function colorFor(int $year): string
    {
        if ($year <= self::STOPS[0][0]) {
            return $this->rgb(self::STOPS[0][1]);
        }
        for ($i = 1, $n = \count(self::STOPS); $i < $n; ++$i) {
            [$y2, $c2] = self::STOPS[$i];
            if ($year <= $y2) {
                [$y1, $c1] = self::STOPS[$i - 1];
                $span = $y2 - $y1;
                if ($span <= 0) {
                    return $this->rgb($c2);
                }
                $t = ($year - $y1) / $span;

                return $this->rgb([
                    (int) round($c1[0] + $t * ($c2[0] - $c1[0])),
                    (int) round($c1[1] + $t * ($c2[1] - $c1[1])),
                    (int) round($c1[2] + $t * ($c2[2] - $c1[2])),
                ]);
            }
        }

        return $this->rgb(self::STOPS[\count(self::STOPS) - 1][1]);
    }

    /**
     * @param array{int, int, int} $c
     */
    private function rgb(array $c): string
    {
        return \sprintf('rgb(%d,%d,%d)', $c[0], $c[1], $c[2]);
    }
}
