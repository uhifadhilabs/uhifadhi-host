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
 * A boundary upload could not be turned into an area — the message is written
 * for the person who uploaded the file, not for a stack trace.
 */
final class BoundaryImportException extends \RuntimeException
{
}
