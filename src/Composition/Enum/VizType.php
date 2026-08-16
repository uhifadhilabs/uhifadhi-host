<?php

declare(strict_types=1);

namespace App\Composition\Enum;

/**
 * The chart form a {@see \App\Composition\Entity\Visualization} takes. The display set (the chip on
 * each viz card) plus the shapes the configure/add builders offer. Rendered server-side as SVG in
 * the existing Twig-chart idiom — no chart library.
 */
enum VizType: string
{
    case Bar = 'bar';
    case Line = 'line';
    case Area = 'area';
    case Scatter = 'scatter';
    case Pie = 'pie';
    case Histogram = 'histogram';
    case Box = 'box';
    case Waterfall = 'waterfall';
    case Lowess = 'lowess';
    case Gantt = 'gantt';
    case Step = 'step';

    public function label(): string
    {
        return $this->value;
    }

    /**
     * The chart types the generic engine can draw from a bound dataset — the ones offered in the
     * configure form. Bar/pie/waterfall take any x + numeric y; the series types want an ordered x;
     * histogram and box read a single numeric column (x is ignored). Gantt needs (label, start, end)
     * — three columns the two-axis form can't bind yet, so it stays excluded.
     *
     * @return list<self>
     */
    public static function editable(): array
    {
        return [
            self::Bar, self::Line, self::Area, self::Scatter,
            self::Pie, self::Histogram, self::Box,
            self::Waterfall, self::Step, self::Lowess,
        ];
    }
}
