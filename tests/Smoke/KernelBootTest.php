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

namespace Uhifadhi\Tests\Smoke;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The cheapest possible canary: the kernel boots with every bundle (including the
 * PostGIS bundle) wired, and the container compiles.
 */
final class KernelBootTest extends KernelTestCase
{
    public function testTheKernelBootsInTheTestEnvironment(): void
    {
        self::bootKernel();

        self::assertSame('test', self::$kernel->getEnvironment());
        self::assertTrue(self::getContainer()->has('doctrine'));
    }
}
