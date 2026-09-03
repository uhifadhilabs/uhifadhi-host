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

namespace Uhifadhi;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * Nothing to add. The `uhifadhi.module` tag used to be autoconfigured here;
 * it now ships with the thing that COLLECTS it — uhifadhi/trunk-module — so a
 * freshly planted seed with that one bundle on it already has a working seam,
 * without an application writing a line of it.
 */
class Kernel extends BaseKernel
{
    use MicroKernelTrait;
}
