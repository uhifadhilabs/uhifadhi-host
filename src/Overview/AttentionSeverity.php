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

namespace Uhifadhi\Overview;

/**
 * How loudly one {@see AttentionItem} asks.
 *
 * THREE STEPS, NOT A NUMBER. A module says which of three things it means and
 * the host sorts by it — a free integer would let two modules invent
 * incompatible scales and the merged list would be sorted by nothing.
 *
 * The words are the module's promise about time, not about importance: `Now`
 * means somebody has to act today, `Soon` means this week, `Watch` means it is
 * being carried and would be missed if it were not written down.
 */
enum AttentionSeverity: string
{
    case Now = 'now';
    case Soon = 'soon';
    case Watch = 'watch';

    /** Loudest first — the order the host's one list is sorted in. */
    public function rank(): int
    {
        return match ($this) {
            self::Now => 0,
            self::Soon => 1,
            self::Watch => 2,
        };
    }

    /** The modifier the row's markup wears; the stylesheet colours the rail from it. */
    public function cssClass(): string
    {
        return $this->value;
    }
}
