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

namespace Uhifadhi\Telemetry\Model;

/**
 * What Telemetry keeps of an uploaded file — and, deliberately, all it keeps.
 *
 * A patrol-with-photo sync carries megabytes of JPEG per request. Storing those
 * bytes would turn the monitor into a second copy of the evidence store and fill
 * the telemetry database in a day, so the bytes are hashed and thrown away and
 * only this shape survives: enough to see that a file arrived, how big it was,
 * what it claimed to be, and — via the digest — whether two requests carried the
 * same one. The digest is where an "invalid payload" caused by a truncated or
 * re-encoded upload shows itself.
 */
final readonly class FileMetadata
{
    public function __construct(
        public string $field,
        public ?string $originalName,
        public int $size,
        public ?string $mimeType,
        public ?string $sha256,
    ) {
    }

    /** @return array{field: string, originalName: ?string, size: int, mimeType: ?string, sha256: ?string} */
    public function toArray(): array
    {
        return [
            'field' => $this->field,
            'originalName' => $this->originalName,
            'size' => $this->size,
            'mimeType' => $this->mimeType,
            'sha256' => $this->sha256,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            self::str($data['field'] ?? '') ?? '',
            self::str($data['originalName'] ?? null),
            is_numeric($data['size'] ?? null) ? (int) $data['size'] : 0,
            self::str($data['mimeType'] ?? null),
            self::str($data['sha256'] ?? null),
        );
    }

    private static function str(mixed $value): ?string
    {
        return \is_scalar($value) ? (string) $value : null;
    }
}
