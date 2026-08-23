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
use Uhifadhi\Service\ModuleEntryRouteResolver;
use UhifadhiLabs\ModuleContracts\ModuleProviderInterface;
use UhifadhiLabs\ModuleContracts\ModuleProviderTrait;

/**
 * Where a module tile links: a module whose provider owns its pages returns a
 * route name; a built-in (no provider, or a null entry route) returns null and
 * the grid falls back to the generic module page.
 */
final class ModuleEntryRouteResolverTest extends TestCase
{
    private function provider(string $slug, ?string $entryRoute): ModuleProviderInterface
    {
        return new class($slug, $entryRoute) implements ModuleProviderInterface {
            use ModuleProviderTrait;

            public function __construct(private string $s, private ?string $route)
            {
            }

            public function slug(): string
            {
                return $this->s;
            }

            public function name(): string
            {
                return ucfirst($this->s);
            }

            public function category(): string
            {
                return 'pressure';
            }

            public function entryRoute(): ?string
            {
                return $this->route;
            }
        };
    }

    public function testReturnsTheOwnRouteForAModuleThatHasOne(): void
    {
        $resolver = new ModuleEntryRouteResolver([
            $this->provider('uhakiki', 'uhakiki_area'),
            $this->provider('legacy', null),
        ]);

        self::assertSame('uhakiki_area', $resolver->entryRouteFor('uhakiki'));
    }

    public function testReturnsNullForAProviderWithoutAnOwnRoute(): void
    {
        $resolver = new ModuleEntryRouteResolver([$this->provider('legacy', null)]);

        self::assertNull($resolver->entryRouteFor('legacy'));
    }

    public function testReturnsNullForASlugWithNoProviderAtAll(): void
    {
        $resolver = new ModuleEntryRouteResolver([]);

        self::assertNull($resolver->entryRouteFor('forest'));
    }
}
