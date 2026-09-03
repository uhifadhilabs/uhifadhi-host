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

namespace Uhifadhi\Model;

use UhifadhiLabs\Trunk\Entity\AreaModule;
use UhifadhiLabs\Trunk\Entity\Module;

/**
 * One entry of an area's parked-module shop. A module is parked either because the area has a
 * switched-off {@see AreaModule} row for it, or because the area has no row for it at all — an area
 * created after the catalogue seed ran owns no rows, yet the whole catalogue is still available to
 * it. Availability comes from the catalogue; the row is created only when a module is added.
 */
final readonly class ParkedModule
{
    public function __construct(
        public Module $module,
        public ?AreaModule $assignment = null,
    ) {
    }

    /**
     * Whether the area already owns a (switched-off) row for this module.
     */
    public function isAssigned(): bool
    {
        return null !== $this->assignment;
    }
}
