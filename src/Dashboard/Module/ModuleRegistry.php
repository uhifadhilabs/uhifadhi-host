<?php

declare(strict_types=1);

namespace App\Dashboard\Module;

use App\Spatial\Module\ModuleDefinition;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Collects every {@see ModuleDefinition} tagged `app.module` (each lives in its own bounded context)
 * and resolves one by slug. A slug with no shipped class gets a {@see GenericModule} — every hook at
 * its default — so data-only modules need no PHP at all. This is how the generic layer stays closed:
 * adding a module never edits a generic class, it only adds a tagged definition.
 */
final class ModuleRegistry
{
    /**
     * @param iterable<ModuleDefinition> $definitions
     */
    public function __construct(
        #[AutowireIterator('app.module')]
        private readonly iterable $definitions,
    ) {
    }

    public function definitionFor(string $slug): ModuleDefinition
    {
        foreach ($this->definitions as $definition) {
            if ($definition->slug() === $slug) {
                return $definition;
            }
        }

        return new GenericModule($slug);
    }
}
