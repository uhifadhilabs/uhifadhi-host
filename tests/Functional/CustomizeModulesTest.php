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

namespace Uhifadhi\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Entity\AreaModule;
use Uhifadhi\Enum\ModuleCategory;
use Uhifadhi\Enum\ModuleStatus;
use Uhifadhi\Factory\AreaModuleFactory;
use Uhifadhi\Factory\AreaOfInterestFactory;
use Uhifadhi\Factory\ModuleFactory;

/**
 * The customize-modules page and its mutations, through a real login (composing an area is the
 * module.create capability, held by Manager and up): the page lists an area's active modules and
 * its parked shop; toggling parks a module; +Add re-activates a parked one.
 */
final class CustomizeModulesTest extends AuthenticatedWebTestCase
{
    public function testThePageListsActiveModulesAndTheParkedShop(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne(['name' => 'Kitulo']);
        $this->assign($area, 'overview', 'Overview', pinned: true, active: true, position: 0);
        $this->assign($area, 'forest', 'Forest loss', active: true, position: 1, status: ModuleStatus::Live);
        $this->assign($area, 'fires', 'Fires', active: false, position: 2, category: ModuleCategory::Pressure);

        $client->request('GET', '/areas/'.$area->getUuidString().'/modules/customize');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#settings-active', 'Forest loss');
        self::assertSelectorTextContains('#settings-active .lib', '2 on'); // overview + forest
        self::assertSelectorTextContains('#settings-shop', 'Fires');
    }

    public function testTogglingParksAModule(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne();
        $forest = $this->assign($area, 'forest', 'Forest loss', active: true, position: 1);

        $crawler = $client->request('GET', '/areas/'.$area->getUuidString().'/modules/customize');
        $token = $crawler->filter('form[action$="/'.$forest->getUuidString().'/toggle"] input[name="_token"]')->attr('value');
        $client->request('POST', '/areas/'.$area->getUuidString().'/modules/customize/'.$forest->getUuidString().'/toggle', ['_token' => $token]);

        self::assertResponseRedirects('/areas/'.$area->getUuidString().'/modules/customize');
        self::assertFalse($this->reload($forest)->isActive(), 'the module is now parked');
    }

    public function testAddReactivatesAParkedModule(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne();
        $roads = $this->assign($area, 'roads', 'Roads', active: false, position: 5, category: ModuleCategory::Pressure);

        $crawler = $client->request('GET', '/areas/'.$area->getUuidString().'/modules/customize');
        $token = $crawler->filter('#settings-shop form input[name="_token"]')->attr('value');
        $client->request('POST', '/areas/'.$area->getUuidString().'/modules/customize/add', ['_token' => $token, 'module' => 'roads']);

        self::assertResponseRedirects('/areas/'.$area->getUuidString().'/modules/customize');
        self::assertTrue($this->reload($roads)->isActive(), 'the parked module is now active');
    }

    private function assign(
        object $area,
        string $slug,
        string $name,
        bool $pinned = false,
        bool $active = true,
        int $position = 0,
        ModuleStatus $status = ModuleStatus::Template,
        ModuleCategory $category = ModuleCategory::Flux,
    ): AreaModule {
        return AreaModuleFactory::createOne([
            'area' => $area,
            'active' => $active,
            'position' => $position,
            'module' => ModuleFactory::new([
                'slug' => $slug, 'name' => $name, 'pinned' => $pinned, 'status' => $status, 'category' => $category,
            ]),
        ]);
    }

    private function reload(AreaModule $areaModule): AreaModule
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->clear();
        $reloaded = $em->getRepository(AreaModule::class)->find($areaModule->getId());
        \assert($reloaded instanceof AreaModule);

        return $reloaded;
    }
}
