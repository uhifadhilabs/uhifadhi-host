<?php

declare(strict_types=1);

namespace App\Forest\Service;

/**
 * Server-rendered SVG for the Forest-loss module's supporting plots (the annual
 * bars live in a Twig partial because they carry the map interaction hooks). Each
 * method returns a complete <svg class="ch"> string in the app's chart dialect
 * (.grid/.ref/.ax/.anno), computed from the real forest_loss_year / dataset_run
 * data — a faithful in-app port of the design's Forest page (six plots total).
 */
final class ForestChartService
{
    private const int W = 470;
    private const int H = 176;
    private const int L = 44;
    private const int R = 12;
    private const int T = 22;
    private const int B = 24;

    private const string ORANGE = '#D97742';
    private const string GREY = '#8B8177';

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
        return \sprintf('<svg class="ch" viewBox="0 0 %d %d" role="img" aria-label="%s">', self::W, self::H, $label);
    }

    /** y gridlines + labels for a [0,ymax] axis; returns the SVG + the ymax used. */
    private function yGrid(float $ymax, string $unit): string
    {
        $out = '';
        $step = $ymax / 4;
        for ($k = 0; $k <= 4; ++$k) {
            $v = $step * $k;
            $gy = round(self::T + $this->ph() - ($v / $ymax) * $this->ph(), 1);
            $out .= \sprintf('<line class="grid" x1="%d" y1="%s" x2="%d" y2="%s"/>', self::L, $gy, self::W - self::R, $gy);
            $out .= \sprintf('<text x="%d" y="%s" text-anchor="end">%s</text>', self::L - 5, $gy + 2.5, $this->fmt($v));
        }
        $out .= \sprintf('<text x="%d" y="%d" text-anchor="end">%s</text>', self::L - 5, self::T - 6, $unit);

        return $out;
    }

    private function xAxis(): string
    {
        return \sprintf('<line class="ax" x1="%d" y1="%s" x2="%d" y2="%s"/>', self::L, self::T + $this->ph(), self::W - self::R, self::T + $this->ph());
    }

    private function fmt(float $v): string
    {
        return number_format(round($v));
    }

    private function niceMax(float $peak): float
    {
        if ($peak <= 0) {
            return 50;
        }
        $mag = 10 ** floor(log10($peak));
        $steps = [1, 1.5, 2, 2.5, 3, 4, 5, 7.5, 10];
        foreach ($steps as $s) {
            if ($s * $mag >= $peak) {
                return $s * $mag;
            }
        }

        return 10 * $mag;
    }

    /**
     * Cumulative loss curve — the running total (a flattening curve = slowing loss).
     *
     * @param list<array{year:int,ha:float}> $series
     */
    public function cumulative(array $series): string
    {
        if ([] === $series) {
            return '';
        }
        $cum = 0.0;
        $pts = [];
        foreach ($series as $row) {
            $cum += $row['ha'];
            $pts[] = ['year' => $row['year'], 'cum' => $cum];
        }
        $ymax = $this->niceMax($cum);
        $n = \count($pts);
        $x = fn (int $i) => self::L + ($n > 1 ? $i / ($n - 1) : 0.5) * $this->pw();
        $y = fn (float $v) => self::T + $this->ph() - ($v / $ymax) * $this->ph();

        $line = '';
        $area = \sprintf('M%s %s', round($x(0), 1), round($y(0), 1));
        foreach ($pts as $i => $p) {
            $px = round($x($i), 1);
            $py = round($y($p['cum']), 1);
            $line .= ('' === $line ? 'M' : 'L').$px.' '.$py.' ';
            $area .= 'L'.$px.' '.$py.' ';
        }
        $area .= \sprintf('L%s %s Z', round($x($n - 1), 1), round($y(0), 1));

        $out = $this->open('Cumulative forest loss, hectares since first year');
        $out .= $this->yGrid($ymax, 'ha');
        $out .= \sprintf('<path d="%s" fill="%s" fill-opacity="0.14"/>', $area, self::ORANGE);
        $out .= \sprintf('<path d="%s" fill="none" stroke="%s" stroke-width="2"/>', trim($line), self::ORANGE);
        $last = end($pts);
        $out .= \sprintf('<circle cx="%s" cy="%s" r="2.6" fill="%s"/>', round($x($n - 1), 1), round($y($last['cum']), 1), self::ORANGE);
        $out .= \sprintf('<text class="anno" x="%s" y="%s" text-anchor="end">%s ha since %d</text>', self::W - self::R, round($y($last['cum']) - 5, 1), $this->fmt($last['cum']), $series[0]['year']);
        // x ticks every 4 years
        foreach ($pts as $i => $p) {
            if (1 === $p['year'] % 4) {
                $out .= \sprintf('<text x="%s" y="%d" text-anchor="middle">%s</text>', round($x($i), 1), self::H - 8, substr((string) $p['year'], 2));
            }
        }
        $out .= $this->xAxis().'</svg>';

        return $out;
    }

    /**
     * Waterfall decomposition — the 2001 baseline artifact (grey) vs genuine
     * multi-year periods, additively building to the total.
     *
     * @param list<array{year:int,ha:float}> $series
     */
    public function waterfall(array $series): string
    {
        if ([] === $series) {
            return '';
        }
        $byYear = [];
        foreach ($series as $row) {
            $byYear[$row['year']] = $row['ha'];
        }
        $periods = [
            ['label' => '2001 artifact', 'lo' => 2001, 'hi' => 2001, 'artifact' => true],
            ['label' => '2002–07', 'lo' => 2002, 'hi' => 2007, 'artifact' => false],
            ['label' => '2008–13', 'lo' => 2008, 'hi' => 2013, 'artifact' => false],
            ['label' => '2014–19', 'lo' => 2014, 'hi' => 2019, 'artifact' => false],
            ['label' => '2020–23', 'lo' => 2020, 'hi' => 2023, 'artifact' => false],
        ];
        $bars = [];
        $total = 0.0;
        foreach ($periods as $p) {
            $sum = 0.0;
            for ($yr = $p['lo']; $yr <= $p['hi']; ++$yr) {
                $sum += $byYear[$yr] ?? 0.0;
            }
            if ($sum <= 0) {
                continue;
            }
            $bars[] = ['label' => $p['label'], 'sum' => $sum, 'artifact' => $p['artifact'], 'base' => $total];
            $total += $sum;
        }
        $ymax = $this->niceMax($total);
        $cols = \count($bars) + 1; // + total bar
        $slot = $this->pw() / $cols;
        $bw = $slot * 0.6;
        $y = fn (float $v) => self::T + $this->ph() - ($v / $ymax) * $this->ph();

        $out = $this->open('Forest-loss decomposition by period, hectares');
        $out .= $this->yGrid($ymax, 'ha');
        $i = 0;
        foreach ($bars as $bar) {
            $cx = self::L + ($i + 0.5) * $slot;
            $top = $y($bar['base'] + $bar['sum']);
            $bot = $y($bar['base']);
            $col = $bar['artifact'] ? self::GREY : self::ORANGE;
            $out .= \sprintf('<rect x="%s" y="%s" width="%s" height="%s" rx="1.5" fill="%s"><title>%s: %s ha</title></rect>', round($cx - $bw / 2, 1), round($top, 1), round($bw, 1), round(max(1, $bot - $top), 1), $col, $bar['label'], $this->fmt($bar['sum']));
            // connector to next
            $out .= \sprintf('<line class="grid" x1="%s" y1="%s" x2="%s" y2="%s"/>', round($cx + $bw / 2, 1), round($top, 1), round($cx + $slot - $bw / 2, 1), round($top, 1));
            $out .= \sprintf('<text x="%s" y="%d" text-anchor="middle">%s</text>', round($cx, 1), self::H - 8, $bar['label']);
            ++$i;
        }
        // total bar
        $cx = self::L + ($i + 0.5) * $slot;
        $out .= \sprintf('<rect x="%s" y="%s" width="%s" height="%s" rx="1.5" fill="%s"/>', round($cx - $bw / 2, 1), round($y($total), 1), round($bw, 1), round($this->ph() - ($y($total) - self::T), 1), '#3ED9A8');
        $out .= \sprintf('<text class="anno" x="%s" y="%s" text-anchor="middle">%s</text>', round($cx, 1), round($y($total) - 4, 1), $this->fmt($total));
        $out .= \sprintf('<text x="%s" y="%d" text-anchor="middle">total</text>', round($cx, 1), self::H - 8);
        $out .= $this->xAxis().'</svg>';

        return $out;
    }

    /**
     * Loss trend — yearly points (2001 excluded) with a smoothed trend line
     * (centred moving average) to show the drift a straight line would deny.
     *
     * @param list<array{year:int,ha:float}> $series
     */
    public function trend(array $series): string
    {
        $pts = array_values(array_filter($series, fn ($r) => 2001 !== $r['year']));
        if (\count($pts) < 2) {
            return '';
        }
        $ha = array_map(fn ($r) => $r['ha'], $pts);
        $ymax = $this->niceMax(max($ha));
        $n = \count($pts); // guaranteed >= 2 by the early return
        $x = fn (int $i) => self::L + ($i / ($n - 1)) * $this->pw();
        $y = fn (float $v) => self::T + $this->ph() - ($v / $ymax) * $this->ph();

        // centred moving average (window 5) as the smoother
        $smooth = [];
        for ($i = 0; $i < $n; ++$i) {
            $lo = max(0, $i - 2);
            $hi = min($n - 1, $i + 2);
            $slice = \array_slice($ha, $lo, $hi - $lo + 1);
            $smooth[$i] = array_sum($slice) / \count($slice);
        }

        $out = $this->open('Forest-loss trend with smoother, hectares per year');
        $out .= $this->yGrid($ymax, 'ha');
        foreach ($pts as $i => $p) {
            $out .= \sprintf('<circle cx="%s" cy="%s" r="2.4" fill="%s" fill-opacity="0.7"><title>%d: %s ha</title></circle>', round($x($i), 1), round($y($p['ha']), 1), self::ORANGE, $p['year'], $this->fmt($p['ha']));
        }
        $line = '';
        foreach ($smooth as $i => $v) {
            $line .= ('' === $line ? 'M' : 'L').round($x($i), 1).' '.round($y($v), 1).' ';
        }
        $out .= \sprintf('<path d="%s" fill="none" stroke="#3ED9A8" stroke-width="2"/>', trim($line));
        foreach ($pts as $i => $p) {
            if (1 === $p['year'] % 4) {
                $out .= \sprintf('<text x="%s" y="%d" text-anchor="middle">%s</text>', round($x($i), 1), self::H - 8, substr((string) $p['year'], 2));
            }
        }
        $out .= $this->xAxis().'</svg>';

        return $out;
    }

    /**
     * Dataset coverage — the data span this park has, over what years (a Gantt row
     * per dataset). Real provenance from the ingested series.
     *
     * @param list<array{year:int,ha:float}> $series
     */
    public function coverage(array $series): string
    {
        if ([] === $series) {
            return '';
        }
        $y0 = $series[0]['year'];
        $y1 = end($series)['year'];
        $rows = [['label' => 'Hansen GFC lossyear', 'lo' => $y0, 'hi' => $y1]];
        $span = max(1, $y1 - $y0);
        $x = fn (int $yr) => self::L + (($yr - $y0) / $span) * $this->pw();

        $out = $this->open('Dataset coverage over years');
        // year gridlines
        for ($yr = $y0; $yr <= $y1; $yr += 4) {
            $gx = round($x($yr), 1);
            $out .= \sprintf('<line class="grid" x1="%s" y1="%d" x2="%s" y2="%s"/>', $gx, self::T, $gx, self::T + $this->ph());
            $out .= \sprintf('<text x="%s" y="%d" text-anchor="middle">%s</text>', $gx, self::H - 8, substr((string) $yr, 2));
        }
        $rowH = 16;
        foreach ($rows as $i => $row) {
            $ry = self::T + 10 + $i * ($rowH + 10);
            $out .= \sprintf('<rect x="%s" y="%s" width="%s" height="%d" rx="3" fill="%s" fill-opacity="0.85"/>', round($x($row['lo']), 1), $ry, round($x($row['hi']) - $x($row['lo']), 1), $rowH, self::ORANGE);
            $out .= \sprintf('<text class="anno" x="%s" y="%s">%s · %d–%d</text>', round($x($row['lo']) + 4, 1), $ry - 4, $row['label'], $row['lo'], $row['hi']);
        }
        $out .= '</svg>';

        return $out;
    }

    /**
     * Shelf growth — the count of successful dataset runs, a step chart that only
     * changes at ingestion events.
     *
     * @param list<string> $runStatuses newest-first list of run statuses
     */
    public function shelf(array $runStatuses): string
    {
        $ok = array_values(array_filter($runStatuses, fn ($s) => 'succeeded' === $s));
        $count = \count($ok);
        $ymax = max(2, $count + 1);
        $steps = max(1, $count);
        $x = fn (float $i) => self::L + ($i / $steps) * $this->pw();
        $y = fn (int $v) => self::T + $this->ph() - ($v / $ymax) * $this->ph();

        $out = $this->open('Datasets on the shelf over ingestion events');
        $out .= $this->yGrid($ymax, 'sets');
        // step from 0 to count
        $path = \sprintf('M%s %s', round($x(0), 1), round($y(0), 1));
        for ($k = 1; $k <= $count; ++$k) {
            $path .= \sprintf('L%s %s L%s %s', round($x($k - 1), 1), round($y($k), 1), round($x($k), 1), round($y($k), 1));
        }
        if (0 === $count) {
            $path .= \sprintf('L%s %s', round($x(1), 1), round($y(0), 1));
        }
        $out .= \sprintf('<path d="%s" fill="none" stroke="#3ED9A8" stroke-width="2"/>', $path);
        $out .= \sprintf('<text class="anno" x="%s" y="%s" text-anchor="end">%d dataset%s ingested</text>', self::W - self::R, self::T + 10, $count, 1 === $count ? '' : 's');
        $out .= $this->xAxis().'</svg>';

        return $out;
    }
}
