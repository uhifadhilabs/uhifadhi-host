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

namespace Uhifadhi\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Enum\PermissionEnum;
use Uhifadhi\Service\PermissionCatalogueService;
use UhifadhiLabs\ModuleContracts\ModulePermission;
use UhifadhiLabs\ModuleContracts\ModuleProviderInterface;
use UhifadhiLabs\ModuleContracts\ModuleProviderTrait;

/**
 * One catalogue for every permission that exists in this deployment: the app's
 * own PermissionEnum plus whatever the installed modules DECLARE. A module's
 * declaration makes its permission assignable (matrix + voter) and nothing
 * more — it grants no capability role and can never shadow a core permission.
 * Uninstall the module and its permissions vanish from the catalogue with it.
 */
final class PermissionCatalogueServiceTest extends TestCase
{
    public function testTheCatalogueIsTheEnumPlusModuleDeclarations(): void
    {
        $catalogue = new PermissionCatalogueService([
            $this->provider('quiet'),
            $this->provider('verifier', [new ModulePermission('verification.tiebreak', 'Verification', 'Tiebreak')]),
        ]);

        $values = array_map(static fn ($permission): string => $permission->value, $catalogue->all());

        foreach (PermissionEnum::cases() as $core) {
            self::assertContains($core->value, $values);
        }
        self::assertContains('verification.tiebreak', $values);
        self::assertCount(\count(PermissionEnum::cases()) + 1, $values);
    }

    public function testHasAnswersForCoreAndContributedAlike(): void
    {
        $catalogue = new PermissionCatalogueService([
            $this->provider('verifier', [new ModulePermission('verification.tiebreak', 'Verification', 'Tiebreak')]),
        ]);

        self::assertTrue($catalogue->has(PermissionEnum::AreaView->value));
        self::assertTrue($catalogue->has('verification.tiebreak'));
        self::assertFalse($catalogue->has('not.a.permission'));
    }

    public function testGroupedByUmbrellaFilesContributedPermissionsUnderTheirOwnGroup(): void
    {
        $catalogue = new PermissionCatalogueService([
            $this->provider('verifier', [new ModulePermission('verification.tiebreak', 'Verification', 'Tiebreak')]),
        ]);

        $grouped = $catalogue->groupedByUmbrella();

        self::assertArrayHasKey('Areas', $grouped);
        self::assertArrayHasKey('Verification', $grouped);
        self::assertSame('verification.tiebreak', $grouped['Verification'][0]->value);
        self::assertSame('Tiebreak', $grouped['Verification'][0]->action);
    }

    public function testAModuleCanNeverShadowACorePermission(): void
    {
        // A hostile or sloppy module redeclaring a CORE value must not replace
        // it (its labels/role) nor duplicate it in the matrix.
        $catalogue = new PermissionCatalogueService([
            $this->provider('impostor', [new ModulePermission(PermissionEnum::AreaDelete->value, 'Trojan', 'Takeover')]),
        ]);

        $areaDeletes = array_values(array_filter(
            $catalogue->all(),
            static fn ($permission): bool => PermissionEnum::AreaDelete->value === $permission->value,
        ));

        self::assertCount(1, $areaDeletes);
        self::assertSame('Areas', $areaDeletes[0]->umbrella, 'The core definition wins.');
    }

    public function testContributedPermissionsCarryNoCapabilityRole(): void
    {
        // Declared, never granted: a module permission must not mint host
        // security roles — only core enum permissions map to ROLE_* umbrellas.
        $catalogue = new PermissionCatalogueService([
            $this->provider('verifier', [new ModulePermission('verification.tiebreak', 'Verification', 'Tiebreak')]),
        ]);

        foreach ($catalogue->all() as $permission) {
            if ('verification.tiebreak' === $permission->value) {
                self::assertNull($permission->capabilityRole);
            }
            if (PermissionEnum::AreaView->value === $permission->value) {
                self::assertSame(PermissionEnum::AreaView->capabilityRole(), $permission->capabilityRole);
            }
        }
    }

    /** @param list<ModulePermission> $permissions */
    private function provider(string $slug, array $permissions = []): ModuleProviderInterface
    {
        return new class($slug, $permissions) implements ModuleProviderInterface {
            use ModuleProviderTrait;

            /** @param list<ModulePermission> $permissions */
            public function __construct(
                private readonly string $slug,
                private readonly array $permissions,
            ) {
            }

            public function slug(): string
            {
                return $this->slug;
            }

            public function name(): string
            {
                return ucfirst($this->slug);
            }

            public function category(): string
            {
                return 'pressure';
            }

            public function permissions(): array
            {
                return $this->permissions;
            }
        };
    }
}
