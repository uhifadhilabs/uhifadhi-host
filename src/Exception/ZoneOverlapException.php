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

namespace Uhifadhi\Exception;

use Uhifadhi\Entity\Zone;

/**
 * The zone invariant was broken: the geometry would share interior with a sibling zone.
 * The message names the conflicting zone, because "somewhere else" is useless to the
 * admin drawing it.
 */
final class ZoneOverlapException extends \RuntimeException
{
    public static function between(string $name, Zone $conflicting): self
    {
        return new self(\sprintf(
            'Zone "%s" overlaps zone "%s" — zones of one area may touch along an edge or leave gaps, but never share interior.',
            $name,
            $conflicting->getName() ?? '(unnamed)',
        ));
    }

    public static function betweenNames(string $name, string $conflictingName): self
    {
        return new self(\sprintf(
            'Zone "%s" overlaps zone "%s" — zones of one area may touch along an edge or leave gaps, but never share interior.',
            $name,
            $conflictingName,
        ));
    }
}
