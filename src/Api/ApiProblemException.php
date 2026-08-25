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

namespace Uhifadhi\Api;

/**
 * An error the field app is expected to understand — API-CONTRACT.md §10.
 *
 * The app's whole failure policy keys off two things: the HTTP status and
 * `retryable`. Getting `retryable` wrong is worse than getting the message
 * wrong: a false `true` makes a phone retry a permanently broken part forever,
 * and a false `false` parks work that would have gone through. So it is an
 * explicit constructor argument here and never inferred at the edge.
 *
 * Named `problemCode`, not `code`: \Exception already owns a `$code` property
 * and a FINAL getCode(), so the contract's string code needs its own name.
 */
final class ApiProblemException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $details context the app re-queues from
     *                                      (e.g. the missing part ids)
     */
    public function __construct(
        private readonly int $statusCode,
        private readonly string $problemCode,
        string $message,
        private readonly bool $retryable = false,
        private readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getProblemCode(): string
    {
        return $this->problemCode;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    /** @return array<string, mixed> */
    public function getDetails(): array
    {
        return $this->details;
    }
}
