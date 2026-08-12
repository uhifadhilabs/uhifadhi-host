<?php

declare(strict_types=1);

namespace App\Tests\Unit\Access\Enum;

use App\Access\Enum\TeamRoleEnum;
use PHPUnit\Framework\TestCase;

/**
 * The tier capability gradient: only Super Admin impersonates; Super Admin / Admin / Manager
 * both administer the team (create/assign positions) and hold every content permission by tier;
 * Staff do neither and are limited to their assigned position.
 */
final class TeamRoleEnumTest extends TestCase
{
    public function testOnlySuperAdminMayImpersonate(): void
    {
        self::assertTrue(TeamRoleEnum::SuperAdmin->canSwitch());
        self::assertFalse(TeamRoleEnum::Admin->canSwitch());
        self::assertFalse(TeamRoleEnum::Manager->canSwitch());
        self::assertFalse(TeamRoleEnum::Staff->canSwitch());
    }

    public function testManagerAndAboveMayAdministerTheTeam(): void
    {
        self::assertTrue(TeamRoleEnum::SuperAdmin->canManageTeam());
        self::assertTrue(TeamRoleEnum::Admin->canManageTeam());
        self::assertTrue(TeamRoleEnum::Manager->canManageTeam());
        self::assertFalse(TeamRoleEnum::Staff->canManageTeam());
    }

    public function testManagerAndAboveHoldEveryPermissionByTier(): void
    {
        self::assertTrue(TeamRoleEnum::SuperAdmin->canManageContent());
        self::assertTrue(TeamRoleEnum::Admin->canManageContent());
        self::assertTrue(TeamRoleEnum::Manager->canManageContent());
        self::assertFalse(TeamRoleEnum::Staff->canManageContent());
    }

    public function testEveryTierHasALabel(): void
    {
        self::assertSame('Super Admin', TeamRoleEnum::SuperAdmin->label());
        self::assertSame('Admin', TeamRoleEnum::Admin->label());
        self::assertSame('Manager', TeamRoleEnum::Manager->label());
        self::assertSame('Staff', TeamRoleEnum::Staff->label());
    }
}
