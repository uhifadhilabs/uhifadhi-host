<?php

declare(strict_types=1);

namespace App\Spatial\Exception;

/**
 * A boundary upload could not be turned into an area — the message is written
 * for the person who uploaded the file, not for a stack trace.
 */
final class BoundaryImportException extends \RuntimeException
{
}
