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

/**
 * A widget-preference payload the platform refuses to store: an id the surface's
 * catalogue does not ship, or a shape it cannot read. Preferences arrive from a
 * browser, so the catalogue — not the request — decides what a stored row may
 * contain.
 */
final class InvalidWidgetPreferenceException extends \InvalidArgumentException
{
}
