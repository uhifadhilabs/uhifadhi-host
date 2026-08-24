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
 * A zoning file could not be turned into zones — written for the admin who uploaded it.
 * Every message names the offending feature (and, for an overlap, its partner), because
 * the import is all-or-nothing: nothing was written, and the file has to be fixed.
 */
final class ZoneImportException extends \RuntimeException
{
}
