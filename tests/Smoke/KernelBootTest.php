<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

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
