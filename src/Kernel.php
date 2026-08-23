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
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use UhifadhiLabs\ModuleContracts\ModuleProviderInterface;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Tag every module provider — a built-in module's or an installed module
     * bundle's — with `uhifadhi.module`, so the catalogue seed and the grid
     * collect both through one seam. Done here (not via services.yaml
     * `_instanceof`, which only reaches this app's own services) so a bundle's
     * autoconfigured provider is tagged too.
     */
    protected function build(ContainerBuilder $container): void
    {
        $container->registerForAutoconfiguration(ModuleProviderInterface::class)
            ->addTag('uhifadhi.module');
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
