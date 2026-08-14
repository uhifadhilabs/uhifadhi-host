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
