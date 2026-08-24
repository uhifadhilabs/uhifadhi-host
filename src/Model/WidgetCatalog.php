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

namespace Uhifadhi\Model;

/**
 * THE catalogue of one dashboard surface: which widgets it ships, what each is
 * called, which headed section of the library it belongs to, how wide it sits by
 * default and how wide a person may make it.
 *
 * A surface registers exactly one of these and the dashboard, the widget library
 * and the save endpoint all read it, so a widget can never exist on one screen
 * and not the other. The `surface` string is what a stored preference row is
 * keyed by, so it is stable across releases: two surfaces may both ship a widget
 * called "map" and neither ever sees the other's row.
 *
 * Declaration order is the default order; the design's own layout is simply the
 * order the widgets are listed in.
 */
final readonly class WidgetCatalog
{
    /** @var array<string, WidgetGroup> */
    private array $groups;

    /** @var array<string, Widget> */
    private array $widgets;

    /**
     * @param string            $surface identifies the dashboard, e.g. 'departments'
     * @param list<WidgetGroup> $groups  the library's headed sections, in the order it draws them
     * @param list<Widget>      $widgets the surface's widgets, in the order the design lays them out
     */
    public function __construct(
        public string $surface,
        array $groups,
        array $widgets,
    ) {
        if ('' === $surface) {
            throw new \InvalidArgumentException('A widget catalogue must name its surface.');
        }
        if ([] === $groups || [] === $widgets) {
            throw new \InvalidArgumentException(\sprintf('The "%s" widget catalogue must ship at least one group and one widget.', $surface));
        }

        $byGroupId = [];
        foreach ($groups as $group) {
            if (isset($byGroupId[$group->id])) {
                throw new \InvalidArgumentException(\sprintf('The "%s" widget catalogue declares the group "%s" twice.', $surface, $group->id));
            }
            $byGroupId[$group->id] = $group;
        }

        $byWidgetId = [];
        foreach ($widgets as $widget) {
            if (isset($byWidgetId[$widget->id])) {
                throw new \InvalidArgumentException(\sprintf('The "%s" widget catalogue declares the widget "%s" twice.', $surface, $widget->id));
            }
            if (!isset($byGroupId[$widget->group])) {
                throw new \InvalidArgumentException(\sprintf('Widget "%s" is filed under "%s", which the "%s" catalogue does not declare.', $widget->id, $widget->group, $surface));
            }
            $byWidgetId[$widget->id] = $widget;
        }

        $this->groups = $byGroupId;
        $this->widgets = $byWidgetId;
    }

    /**
     * Every widget id, in catalogue (default) order.
     *
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->widgets);
    }

    public function has(string $id): bool
    {
        return isset($this->widgets[$id]);
    }

    public function get(string $id): Widget
    {
        return $this->widgets[$id]
            ?? throw new \InvalidArgumentException(\sprintf('The "%s" surface ships no widget "%s".', $this->surface, $id));
    }

    /**
     * The library's headed sections, in the order it draws them.
     *
     * @return list<WidgetGroup>
     */
    public function groups(): array
    {
        return array_values($this->groups);
    }

    /**
     * The spans this widget may take, widest first.
     *
     * @return list<int>
     */
    public function spans(string $id): array
    {
        return $this->get($id)->spans;
    }

    /** The allowed span nearest the asked-for one; ties go to the wider. */
    public function clamp(string $id, int $cols): int
    {
        $best = null;
        foreach ($this->spans($id) as $span) {
            if (null === $best || abs($span - $cols) < abs($best - $cols)) {
                $best = $span;
            }
        }

        return $best ?? $this->get($id)->cols;
    }
}
