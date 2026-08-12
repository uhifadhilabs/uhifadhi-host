<?php

declare(strict_types=1);

namespace App\Access;

/**
 * Marker for the Access bounded context — authentication and position/permission
 * authorization for the single authority that runs uhifadhi.
 *
 * uhifadhi is single-org (one authority/NCAA), so this drops vivutio's supplier/operator
 * party split and single-table inheritance: one {@see Entity\User}, a {@see Enum\TeamRoleEnum}
 * tier, and an optional {@see Entity\Position} bundling granular {@see Enum\PermissionEnum}s
 * gated by {@see Security\PermissionVoter}.
 *
 * May depend on Foundation only. Dashboard (the UI layer) may depend on Access.
 *
 * Structural marker only.
 */
final class Access
{
}
