<?php

declare(strict_types=1);

namespace App\Tests\Functional\Dashboard;

use App\Composition\Entity\AreaModule;
use App\Composition\Entity\Visualization;
use App\Composition\Factory\AreaModuleFactory;
use App\Composition\Factory\ModuleFactory;
use App\Composition\Factory\VisualizationFactory;
use App\Spatial\Factory\AreaOfInterestFactory;
use App\Tests\Functional\AuthenticatedWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Visualization editing on the module's Settings tab (module.create): the Settings page lists the
 * module's visualizations, and a viz can be added, removed and configured — all on one Settings page,
 * with the mutations posting to /settings/viz/* and returning to Settings.
 */
final class ModuleEditTest extends AuthenticatedWebTestCase
{
    public function testTheSettingsTabListsTheModulesVisualizations(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $forest = $this->forestModuleOn(AreaOfInterestFactory::createOne(['name' => 'Katavi']));
        VisualizationFactory::createOne(['areaModule' => $forest, 'title' => 'Annual loss', 'position' => 0]);

        $client->request('GET', $this->settingsUrl($forest));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.atabs a.on', 'Settings');
        self::assertSelectorTextContains('.viz-row-title', 'Annual loss');
    }

    public function testAddingAVisualizationCreatesOne(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $forest = $this->forestModuleOn(AreaOfInterestFactory::createOne());

        $crawler = $client->request('GET', $this->settingsUrl($forest));
        $token = $crawler->filter('form[action$="/viz/add"] input[name="_token"]')->attr('value');
        $client->request('POST', $this->settingsUrl($forest).'/viz/add', ['_token' => $token]);

        self::assertResponseStatusCodeSame(302); // back to Settings with the configure modal open
        $vizzes = $this->em()->getRepository(Visualization::class)->findBy(['areaModule' => $forest->getId()]);
        self::assertCount(1, $vizzes);
        self::assertSame('New visualization', $vizzes[0]->getTitle());
    }

    public function testRemovingAVisualizationDeletesIt(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $forest = $this->forestModuleOn(AreaOfInterestFactory::createOne());
        $viz = VisualizationFactory::createOne(['areaModule' => $forest, 'title' => 'Scrap', 'position' => 0]);

        $crawler = $client->request('GET', $this->settingsUrl($forest));
        $token = $crawler->filter('form[action$="/'.$viz->getUuidString().'/delete"] input[name="_token"]')->attr('value');
        $client->request('POST', $this->settingsUrl($forest).'/viz/'.$viz->getUuidString().'/delete', ['_token' => $token]);

        self::assertResponseStatusCodeSame(302);
        self::assertNull($this->em()->getRepository(Visualization::class)->find($viz->getId()));
    }

    public function testConfigureParamOpensTheModalOnTheSettingsPage(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $forest = $this->forestModuleOn(AreaOfInterestFactory::createOne());
        $viz = VisualizationFactory::createOne(['areaModule' => $forest, 'title' => 'Tune me', 'position' => 0]);

        $client->request('GET', $this->settingsUrl($forest).'?configure='.$viz->getUuidString());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.modal-title', 'Configure visualization');
        self::assertSelectorExists('.modal form input[name="title"]');
    }

    private function forestModuleOn(object $area): AreaModule
    {
        return AreaModuleFactory::createOne([
            'area' => $area, 'active' => true, 'position' => 1,
            'module' => ModuleFactory::new(['slug' => 'forest', 'name' => 'Forest loss']),
        ]);
    }

    private function settingsUrl(AreaModule $areaModule): string
    {
        return '/areas/'.$areaModule->getArea()?->getUuidString().'/forest/settings';
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->clear();

        return $em;
    }
}
