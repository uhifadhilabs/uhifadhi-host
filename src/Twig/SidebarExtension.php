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

namespace Uhifadhi\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Declares `sidebar_nav()`. Nothing more — the tree itself is built by {@see SidebarRuntime}.
 *
 * The split is not decoration. Twig constructs every EXTENSION as soon as the `twig` service is
 * built, and the image build does exactly that: `asset-map:compile` fires the asset-compile
 * event, UX Icons warms its icon cache off it, and the icon finder needs Twig. A build stage has
 * no DATABASE_URL, so an extension holding a Doctrine repository kills the build. A RUNTIME is
 * constructed lazily, on the first `sidebar_nav()` call — i.e. only when a template is actually
 * rendered, which is a request, which has a database.
 */
final class SidebarExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [new TwigFunction('sidebar_nav', [SidebarRuntime::class, 'build'])];
    }
}
