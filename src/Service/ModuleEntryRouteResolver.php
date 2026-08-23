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

namespace Uhifadhi\Service;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use UhifadhiLabs\ModuleContracts\ModuleProviderInterface;

/**
 * Resolves where a module tile/nav entry links. A module whose provider owns
 * its pages returns a route name (the host links it with the area uuid); a
 * built-in module — no provider, or one with a null entry route — returns null
 * and the grid uses the generic module page. Keeping this live off the tagged
 * providers means the route stays code (never a stale value in a DB column).
 */
final class ModuleEntryRouteResolver
{
    /**
     * @param iterable<ModuleProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator('uhifadhi.module')]
        private readonly iterable $providers = [],
    ) {
    }

    public function entryRouteFor(string $slug): ?string
    {
        foreach ($this->providers as $provider) {
            if ($provider->slug() === $slug) {
                return $provider->entryRoute();
            }
        }

        return null;
    }
}
