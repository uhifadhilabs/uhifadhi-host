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

namespace Uhifadhi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Service\TeamService;

/**
 * The password a created member is handed. It is generated, never invented by the admin, and it
 * is READ ALOUD OR TYPED FROM A NOTE — so the alphabet drops the characters that a reader cannot
 * tell apart (0/O, 1/l/I) rather than maximising entropy per character.
 */
final class GeneratedPasswordTest extends TestCase
{
    public function testItIsLongEnoughToBeHandedOverOnce(): void
    {
        self::assertSame(20, \strlen(TeamService::generatePassword()));
    }

    public function testItAvoidsCharactersAReaderConfuses(): void
    {
        for ($i = 0; $i < 50; ++$i) {
            self::assertMatchesRegularExpression('/^[abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789]+$/', TeamService::generatePassword());
        }
    }

    public function testTwoPasswordsAreNotTheSame(): void
    {
        self::assertNotSame(TeamService::generatePassword(), TeamService::generatePassword());
    }
}
