<?php

declare(strict_types=1);

namespace App\Access\Enum;

/**
 * A user's tier within the single authority that runs uhifadhi. There is no "Owner" —
 * nobody owns a national park. Super Admin / Admin / Manager hold every permission by
 * tier ({@see canManageContent()}); Staff hold exactly their {@see \App\Access\Entity\Position}.
 * Permission queries live here to keep the user entity thin.
 */
enum TeamRoleEnum: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Manager = 'manager';
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Admin => 'Admin',
            self::Manager => 'Manager',
            self::Staff => 'Staff',
        };
    }

    /** Super Admin may impersonate any user (switch_user / ROLE_ALLOWED_TO_SWITCH). */
    public function canSwitch(): bool
    {
        return self::SuperAdmin === $this;
    }

    /** Super Admin, Admin and Manager may administer the team — create/edit/delete positions and assign members. */
    public function canManageTeam(): bool
    {
        return \in_array($this, [self::SuperAdmin, self::Admin, self::Manager], true);
    }

    /** Super Admin and Admin hold every permission by tier (used by the voter); everyone
     * else — Manager included — holds exactly their Position's permissions. */
    public function canManageContent(): bool
    {
        return \in_array($this, [self::SuperAdmin, self::Admin], true);
    }
}
