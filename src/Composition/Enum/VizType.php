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
    case Box = 'box';
    case Waterfall = 'waterfall';
    case Lowess = 'lowess';
    case Gantt = 'gantt';
    case Step = 'step';

    public function label(): string
    {
        return match ($this) {
            self::Bar => 'bar',
            self::Line => 'line',
            self::Area => 'area',
            self::Scatter => 'scatter',
            self::Box => 'box',
            self::Waterfall => 'waterfall',
            self::Lowess => 'lowess',
            self::Gantt => 'gantt',
            self::Step => 'step',
        };
    }

    /**
     * The types offered in the "configure visualization" editor's Type select (Bar/Line/Area/Scatter).
     *
     * @return list<self>
     */
    /**
     * The chart types the generic engine can draw from a bound (x, y) column pair — the ones offered
     * in the configure form. Gantt (date ranges) and Box (distributions) need a different data shape,
     * so they are excluded until the engine grows to draw them.
     *
     * @return list<self>
     */
    public static function editable(): array
    {
        return [self::Bar, self::Line, self::Area, self::Scatter, self::Waterfall, self::Step, self::Lowess];
    }
}
