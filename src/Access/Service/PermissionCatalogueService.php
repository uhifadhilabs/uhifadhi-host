<?php

declare(strict_types=1);

namespace Uhifadhi\Access\Service;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Uhifadhi\Access\Enum\PermissionEnum;
use Uhifadhi\Access\Model\Permission;
use UhifadhiLabs\ModuleContracts\ModuleProviderInterface;

/**
 * The single catalogue of every permission that exists in this deployment:
 * the app's own {@see PermissionEnum} plus whatever the installed modules
 * DECLARE through {@see ModuleProviderInterface::permissions()}.
 * Declaring makes a permission assignable — it appears in the /team matrix and
 * the {@see \Uhifadhi\Access\Security\PermissionVoter} recognises it — and
 * nothing more: no capability role, no default holders. Uninstalling a module
 * removes its provider and its permissions vanish from the catalogue with it.
 *
 * Core always wins: a module redeclaring a core value is ignored, so no module
 * can relabel or shadow an app permission.
 */
final class PermissionCatalogueService
{
    /**
     * @param iterable<ModuleProviderInterface> $moduleProviders every installed
     *                                                           module, core and bundle alike (the uhifadhi.module tag)
     */
    public function __construct(
        #[AutowireIterator('uhifadhi.module')]
        private readonly iterable $moduleProviders,
    ) {
    }

    /**
     * @return list<Permission> core first (enum order), then module-declared
     */
    public function all(): array
    {
        $catalogue = [];
        foreach (PermissionEnum::cases() as $core) {
            $catalogue[$core->value] = new Permission(
                $core->value,
                $core->umbrella(),
                $core->action(),
                $core->capabilityRole(),
            );
        }

        foreach ($this->moduleProviders as $provider) {
            foreach ($provider->permissions() as $declared) {
                // First definition wins — core always precedes, and between two
                // modules colliding on a value the earlier registration holds.
                $catalogue[$declared->value] ??= new Permission(
                    $declared->value,
                    $declared->umbrella,
                    $declared->action,
                );
            }
        }

        return array_values($catalogue);
    }

    public function has(string $value): bool
    {
        foreach ($this->all() as $permission) {
            if ($permission->value === $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * The matrix's shape: permissions grouped under their umbrella heading.
     *
     * @return array<string, list<Permission>>
     */
    public function groupedByUmbrella(): array
    {
        $grouped = [];
        foreach ($this->all() as $permission) {
            $grouped[$permission->umbrella][] = $permission;
        }

        return $grouped;
    }

    /**
     * The submitted values that actually exist, in catalogue order — the
     * write-side filter for the position form.
     *
     * @param list<string> $values
     *
     * @return list<string>
     */
    public function knownValues(array $values): array
    {
        $known = [];
        foreach ($this->all() as $permission) {
            if (\in_array($permission->value, $values, true)) {
                $known[] = $permission->value;
            }
        }

        return $known;
    }
}
