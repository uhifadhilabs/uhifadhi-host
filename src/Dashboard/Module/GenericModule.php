<?php

declare(strict_types=1);

namespace Uhifadhi\Dashboard\Module;

use Uhifadhi\Spatial\Module\ModuleDefinition;

/**
 * The definition a module gets when it ships no class of its own: every hook keeps the abstract
 * base's default, so a pure data-only module (engine module + catalogue row, nothing else) still
 * renders through the whole generic surface.
 */
final class GenericModule extends ModuleDefinition
{
    public function __construct(
        private readonly string $slug,
    ) {
    }

    public function slug(): string
    {
        return $this->slug;
    }
}
