<?php

declare(strict_types=1);

namespace App\Composition\Enum;

/**
 * The taxonomy a module belongs to in the "add a module" catalogue (from the design's
 * category filter pills). Overview is the pinned hub and sits in no category — {@see Hub}.
 */
enum ModuleCategory: string
{
    case Hub = 'hub';
    case Flux = 'flux';
    case Pressure = 'pressure';
    case Biodiversity = 'biodiversity';

    public function label(): string
    {
        return match ($this) {
            self::Hub => 'Hub',
            self::Flux => 'Flux',
            self::Pressure => 'Pressure',
            self::Biodiversity => 'Biodiversity',
        };
    }
}
