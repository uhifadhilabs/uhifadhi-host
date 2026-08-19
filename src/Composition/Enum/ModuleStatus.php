<?php

declare(strict_types=1);

namespace Uhifadhi\Composition\Enum;

/**
 * How far along a module is: the Overview {@see Hub}; a {@see Live} module rendering real data
 * (Forest loss); or a {@see Template} module scaffolded until its ingestion lands. Drives the
 * status chip on the module rows and catalogue cards.
 */
enum ModuleStatus: string
{
    case Hub = 'hub';
    case Live = 'live';
    case Template = 'template';

    public function label(): string
    {
        return match ($this) {
            self::Hub => 'hub',
            self::Live => 'live',
            self::Template => 'template',
        };
    }
}
