<?php

declare(strict_types=1);

namespace App\Dashboard\Chart;

/**
 * The module-agnostic plot engine: server-rendered SVG (bar / line / area) from plain (label, value)
 * points, in the app's chart dialect (`<svg class="ch">`, `.grid`/`.ax`). Any bound visualization on
 * any module renders through this — no per-module drawer. Labels come from dataset values, so they are
 * escaped. Mirrors the layout of the Forest module's bespoke charts so the grid reads consistently.
 */
final class ChartSvgService
{
    private const int W = 470;
    private const int H = 176;
    private const int L = 44;
    private const int R = 12;
    private const int T = 22;
    private const int B = 30;

    private const string ACCENT = '#2e7d4f';

    /**
     * @param list<array{label: string, value: float}> $points
     */
    public function bar(array $points, string $unit = ''): string
    {
        if ([] === $points) {
            return '';
        }
        $ymax = $this->niceMax($this->peak($points));
        $slot = $this->pw() / \count($points);
        $barWidth = min($slot * 0.62, 34.0);

        $out = $this->open('Bar chart').$this->yGrid($ymax, $unit).$this->xAxis();
        foreach ($points as $i => $point) {
            $centre = self::L + $slot * ($i + 0.5);
            $height = ($point['value'] / $ymax) * $this->ph();
            $top = self::T + $this->ph() - $height;
            $out .= \sprintf(
                '<rect x="%s" y="%s" width="%s" height="%s" rx="1.5" fill="%s"/>',
                round($centre - $barWidth / 2, 1),
                round($top, 1),
                round($barWidth, 1),
                round($height, 1),
                self::ACCENT,
            );
            $out .= $this->xLabel($centre, $point['label']);
        }

        return $out.'</svg>';
    }

    /**
     * @param list<array{label: string, value: float}> $points
     */
    public function line(array $points, string $unit = ''): string
    {
        return $this->path($points, $unit, fill: false, label: 'Line chart');
    }

    /**
     * @param list<array{label: string, value: float}> $points
     */
    public function area(array $points, string $unit = ''): string
    {
        return $this->path($points, $unit, fill: true, label: 'Area chart');
    }

    /**
     * @param list<array{label: string, value: float}> $points
     */
    private function path(array $points, string $unit, bool $fill, string $label): string
    {
        if ([] === $points) {
            return '';
        }
        $ymax = $this->niceMax($this->peak($points));
        $count = \count($points);
        $x = fn (int $i): float => self::L + ($count > 1 ? $i / ($count - 1) : 0.5) * $this->pw();
        $y = fn (float $value): float => self::T + $this->ph() - ($value / $ymax) * $this->ph();
        $baseline = round($y(0), 1);

        $coords = [];
        foreach ($points as $i => $point) {
            $coords[] = round($x($i), 1).' '.round($y($point['value']), 1);
        }
        $line = 'M'.implode(' L', $coords);

        $out = $this->open($label).$this->yGrid($ymax, $unit).$this->xAxis();
        if ($fill) {
            $areaPath = \sprintf('M%s %s L%s L%s %s Z', round($x(0), 1), $baseline, implode(' L', $coords), round($x($count - 1), 1), $baseline);
            $out .= \sprintf('<path d="%s" fill="%s" fill-opacity="0.14"/>', $areaPath, self::ACCENT);
        }
        $out .= \sprintf('<path d="%s" fill="none" stroke="%s" stroke-width="2"/>', $line, self::ACCENT);
        foreach ($points as $i => $point) {
            $out .= $this->xLabel($x($i), $point['label']);
        }

        return $out.'</svg>';
    }

    /**
     * A dot per point (evenly spaced on x, value on y) — the data-driven scatter/dot plot.
     *
     * @param list<array{label: string, value: float}> $points
     */
    public function scatter(array $points, string $unit = ''): string
    {
        if ([] === $points) {
            return '';
        }
        $ymax = $this->niceMax($this->peak($points));
        [$x, $y] = $this->scales($points, $ymax);

        $out = $this->open('Scatter chart').$this->yGrid($ymax, $unit).$this->xAxis();
        foreach ($points as $i => $point) {
            $out .= \sprintf('<circle cx="%s" cy="%s" r="3" fill="%s"/>', round($x($i), 1), round($y($point['value']), 1), self::ACCENT);
            $out .= $this->xLabel($x($i), $point['label']);
        }

        return $out.'</svg>';
    }

    /**
     * A stepped line (value held until the next point) — for counts/levels that change at events.
     *
     * @param list<array{label: string, value: float}> $points
     */
    public function step(array $points, string $unit = ''): string
    {
        if ([] === $points) {
            return '';
        }
        $ymax = $this->niceMax($this->peak($points));
        [$x, $y] = $this->scales($points, $ymax);

        $out = $this->open('Step chart').$this->yGrid($ymax, $unit).$this->xAxis();
        $d = '';
        foreach ($points as $i => $point) {
            $px = round($x($i), 1);
            $py = round($y($point['value']), 1);
            $d .= 0 === $i ? "M{$px} {$py}" : \sprintf(' L%s %s L%s %s', $px, round($y($points[$i - 1]['value']), 1), $px, $py);
            $out .= $this->xLabel($x($i), $point['label']);
        }

        return $out.\sprintf('<path d="%s" fill="none" stroke="%s" stroke-width="2"/>', $d, self::ACCENT).'</svg>';
    }

    /**
     * Points plus a moving-average smoother — the trend a straight line would deny (a light LOESS).
     *
     * @param list<array{label: string, value: float}> $points
     */
    public function lowess(array $points, string $unit = ''): string
    {
        if ([] === $points) {
            return '';
        }
        $ymax = $this->niceMax($this->peak($points));
        [$x, $y] = $this->scales($points, $ymax);
        $count = \count($points);

        $smoothed = [];
        foreach ($points as $i => $point) {
            $lo = max(0, $i - 1);
            $hi = min($count - 1, $i + 1);
            $sum = 0.0;
            for ($j = $lo; $j <= $hi; ++$j) {
                $sum += $points[$j]['value'];
            }
            $smoothed[$i] = round($x($i), 1).' '.round($y($sum / ($hi - $lo + 1)), 1);
        }

        $out = $this->open('Trend chart').$this->yGrid($ymax, $unit).$this->xAxis();
        $out .= \sprintf('<path d="M%s" fill="none" stroke="%s" stroke-width="2"/>', implode(' L', $smoothed), self::ACCENT);
        foreach ($points as $i => $point) {
            $out .= \sprintf('<circle cx="%s" cy="%s" r="2.5" fill="%s" fill-opacity="0.5"/>', round($x($i), 1), round($y($point['value']), 1), self::ACCENT);
            $out .= $this->xLabel($x($i), $point['label']);
        }

        return $out.'</svg>';
    }

    /**
     * Cumulative bars — each starts where the previous ended, building to the running total.
     *
     * @param list<array{label: string, value: float}> $points
     */
    public function waterfall(array $points, string $unit = ''): string
    {
        if ([] === $points) {
            return '';
        }
        $running = 0.0;
        $peak = 0.0;
        foreach ($points as $point) {
            $running += $point['value'];
            $peak = max($peak, $running);
        }
        $ymax = $this->niceMax($peak);
        $slot = $this->pw() / \count($points);
        $barWidth = min($slot * 0.62, 34.0);
        $y = fn (float $value): float => self::T + $this->ph() - ($value / $ymax) * $this->ph();

        $out = $this->open('Waterfall chart').$this->yGrid($ymax, $unit).$this->xAxis();
        $running = 0.0;
        foreach ($points as $i => $point) {
            $start = $running;
            $running += $point['value'];
            $centre = self::L + $slot * ($i + 0.5);
            $top = min($y($start), $y($running));
            $out .= \sprintf(
                '<rect x="%s" y="%s" width="%s" height="%s" rx="1.5" fill="%s"/>',
                round($centre - $barWidth / 2, 1), round($top, 1), round($barWidth, 1), round(max(abs($y($start) - $y($running)), 1), 1), self::ACCENT,
            );
            $out .= $this->xLabel($centre, $point['label']);
        }

        return $out.'</svg>';
    }

    /** The categorical slice palette (jade-forward, readable on both themes). */
    private const array WHEEL = ['#2e7d4f', '#4fae7d', '#b8a600', '#e59b3a', '#2b7fd6', '#c8642d', '#5ad3c8', '#9876aa', '#b0aead'];

    /**
     * A pie: one slice per (label, value) point, with a compact legend on the right.
     *
     * @param list<array{label: string, value: float}> $points
     */
    public function pie(array $points, string $unit = ''): string
    {
        $total = 0.0;
        foreach ($points as $point) {
            $total += max(0.0, $point['value']);
        }
        if ([] === $points || $total <= 0) {
            return '';
        }

        $cx = 88.0;
        $cy = self::H / 2;
        $r = 62.0;
        $angle = -M_PI / 2; // start at 12 o'clock
        $out = $this->open('Pie chart');
        $legend = '';
        foreach ($points as $i => $point) {
            $share = max(0.0, $point['value']) / $total;
            $sweep = $share * 2 * M_PI;
            $colour = self::WHEEL[$i % \count(self::WHEEL)];
            if ($share >= 0.9999) {
                $out .= \sprintf('<circle cx="%s" cy="%s" r="%s" fill="%s"/>', $cx, $cy, $r, $colour);
            } elseif ($sweep > 0) {
                $x1 = $cx + $r * cos($angle);
                $y1 = $cy + $r * sin($angle);
                $x2 = $cx + $r * cos($angle + $sweep);
                $y2 = $cy + $r * sin($angle + $sweep);
                $out .= \sprintf(
                    '<path d="M%s %s L%s %s A%s %s 0 %d 1 %s %s Z" fill="%s"/>',
                    $cx, $cy, round($x1, 2), round($y1, 2), $r, $r, $sweep > M_PI ? 1 : 0, round($x2, 2), round($y2, 2), $colour,
                );
            }
            $angle += $sweep;

            // Legend: swatch + label + share, two columns when more than six slices.
            $col = intdiv($i, 6);
            $row = $i % 6;
            $lx = 178 + $col * 150;
            $ly = 26 + $row * 22;
            $legend .= \sprintf('<rect x="%d" y="%d" width="9" height="9" rx="2" fill="%s"/>', $lx, $ly - 8, $colour);
            $legend .= \sprintf(
                '<text x="%d" y="%d">%s · %s%%</text>',
                $lx + 14,
                $ly,
                htmlspecialchars(mb_strlen($point['label']) > 14 ? mb_substr($point['label'], 0, 13).'…' : $point['label'], \ENT_QUOTES),
                round($share * 100, $share < 0.01 ? 1 : 0),
            );
        }

        return $out.$legend.'</svg>';
    }

    /**
     * A histogram: the y-values binned into ~10 equal-width classes, drawn as touching bars.
     *
     * @param list<array{label: string, value: float}> $points
     */
    public function histogram(array $points, string $unit = ''): string
    {
        $values = array_map(static fn (array $p): float => $p['value'], $points);
        if ([] === $values) {
            return '';
        }
        $min = min($values);
        $max = max($values);
        $binCount = max(3, min(10, \count($values)));
        $width = ($max - $min) > 0 ? ($max - $min) / $binCount : 1.0;

        $bins = array_fill(0, $binCount, 0);
        foreach ($values as $value) {
            $bins[min($binCount - 1, (int) (($value - $min) / $width))]++;
        }

        $ymax = $this->niceMax((float) max($bins));
        $slot = $this->pw() / $binCount;
        $out = $this->open('Histogram').$this->yGrid($ymax, 'n').$this->xAxis();
        foreach ($bins as $i => $count) {
            $height = ($count / $ymax) * $this->ph();
            $out .= \sprintf(
                '<rect x="%s" y="%s" width="%s" height="%s" fill="%s" fill-opacity="0.9" stroke="#0b0f0d" stroke-width="0.5"/>',
                round(self::L + $slot * $i, 1),
                round(self::T + $this->ph() - $height, 1),
                round($slot, 1),
                round($height, 1),
                self::ACCENT,
            );
            if (0 === $i % 2) { // label every other bin edge to avoid crowding
                $out .= $this->xLabel(self::L + $slot * ($i + 0.5), $this->fmt($min + $width * ($i + 0.5)));
            }
        }

        return $out.'</svg>';
    }

    /**
     * The shared x (by index) / y (by value) scale closures for the line-family charts.
     *
     * @param list<array{label: string, value: float}> $points
     *
     * @return array{0: \Closure(int): float, 1: \Closure(float): float}
     */
    private function scales(array $points, float $ymax): array
    {
        $count = \count($points);

        return [
            fn (int $i): float => self::L + ($count > 1 ? $i / ($count - 1) : 0.5) * $this->pw(),
            fn (float $value): float => self::T + $this->ph() - ($value / $ymax) * $this->ph(),
        ];
    }

    private function pw(): float
    {
        return self::W - self::L - self::R;
    }

    private function ph(): float
    {
        return self::H - self::T - self::B;
    }

    private function open(string $label): string
    {
        return \sprintf('<svg class="ch" viewBox="0 0 %d %d" role="img" aria-label="%s">', self::W, self::H, htmlspecialchars($label, \ENT_QUOTES));
    }

    private function yGrid(float $ymax, string $unit): string
    {
        $out = '';
        for ($k = 0; $k <= 4; ++$k) {
            $value = ($ymax / 4) * $k;
            $gy = round(self::T + $this->ph() - ($value / $ymax) * $this->ph(), 1);
            $out .= \sprintf('<line class="grid" x1="%d" y1="%s" x2="%d" y2="%s"/>', self::L, $gy, self::W - self::R, $gy);
            $out .= \sprintf('<text x="%d" y="%s" text-anchor="end">%s</text>', self::L - 5, $gy + 2.5, $this->fmt($value));
        }
        if ('' !== $unit) {
            $out .= \sprintf('<text x="%d" y="%d" text-anchor="end">%s</text>', self::L - 5, self::T - 6, htmlspecialchars($unit, \ENT_QUOTES));
        }

        return $out;
    }

    private function xAxis(): string
    {
        $ay = self::T + $this->ph();

        return \sprintf('<line class="ax" x1="%d" y1="%s" x2="%d" y2="%s"/>', self::L, $ay, self::W - self::R, $ay);
    }

    private function xLabel(float $centre, string $label): string
    {
        $short = mb_strlen($label) > 9 ? mb_substr($label, 0, 8).'…' : $label;

        return \sprintf(
            '<text class="xlab" x="%s" y="%s" text-anchor="middle">%s</text>',
            round($centre, 1),
            self::T + $this->ph() + 13,
            htmlspecialchars($short, \ENT_QUOTES),
        );
    }

    private function fmt(float $value): string
    {
        return number_format(round($value));
    }

    /**
     * @param list<array{label: string, value: float}> $points
     */
    private function peak(array $points): float
    {
        $max = 0.0;
        foreach ($points as $point) {
            $max = max($max, $point['value']);
        }

        return $max;
    }

    private function niceMax(float $peak): float
    {
        if ($peak <= 0) {
            return 50;
        }
        $magnitude = 10 ** floor(log10($peak));
        foreach ([1, 1.5, 2, 2.5, 3, 4, 5, 7.5, 10] as $step) {
            if ($step * $magnitude >= $peak) {
                return $step * $magnitude;
            }
        }

        return 10 * $magnitude;
    }
}
