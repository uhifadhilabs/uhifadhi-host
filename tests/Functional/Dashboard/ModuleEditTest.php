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
 * Module edit mode (module.create): the page shows the module chip bar and the module's
 * visualization grid; a visualization can be added, removed, and configured. Charts a provider
 * can't draw yet fall back to a scaffold (no forest data seeded here), so the page still renders.
 */
final class ModuleEditTest extends AuthenticatedWebTestCase
{
    public function testTheEditPageShowsTheModuleAndItsVisualizations(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $forest = $this->forestModuleOn(AreaOfInterestFactory::createOne(['name' => 'Katavi']));
        VisualizationFactory::createOne(['areaModule' => $forest, 'title' => 'Annual loss', 'position' => 0]);

        $client->request('GET', $this->editUrl($forest));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.mchip.on', 'Forest loss');
        self::assertSelectorTextContains('.viz-title', 'Annual loss');
        self::assertSelectorExists('.mchip.add'); // + Add module
    }

    public function testAddingAVisualizationCreatesOne(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $forest = $this->forestModuleOn(AreaOfInterestFactory::createOne());

        $crawler = $client->request('GET', $this->editUrl($forest));
        $token = $crawler->filter('form[action$="/viz/add"] input[name="_token"]')->attr('value');
        $client->request('POST', $this->editUrl($forest).'/viz/add', [
            '_token' => $token, 'type' => 'scatter', 'xAxis' => 'Rainfall (mm)', 'yAxis' => 'Loss (ha)',
        ]);

        self::assertResponseRedirects($this->editUrl($forest));
        $vizzes = $this->em()->getRepository(Visualization::class)->findBy(['areaModule' => $forest->getId()]);
        self::assertCount(1, $vizzes);
        self::assertSame('Loss (ha) vs Rainfall (mm)', $vizzes[0]->getTitle());
    }

    public function testRemovingAVisualizationDeletesIt(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $forest = $this->forestModuleOn(AreaOfInterestFactory::createOne());
        $viz = VisualizationFactory::createOne(['areaModule' => $forest, 'title' => 'Scrap', 'position' => 0]);

        $crawler = $client->request('GET', $this->editUrl($forest));
        $token = $crawler->filter('form[action$="/'.$viz->getUuidString().'/delete"] input[name="_token"]')->attr('value');
        $client->request('POST', $this->editUrl($forest).'/viz/'.$viz->getUuidString().'/delete', ['_token' => $token]);

        self::assertResponseRedirects($this->editUrl($forest));
        self::assertNull($this->em()->getRepository(Visualization::class)->find($viz->getId()));
    }

    public function testConfigureParamOpensTheModal(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $forest = $this->forestModuleOn(AreaOfInterestFactory::createOne());
        $viz = VisualizationFactory::createOne(['areaModule' => $forest, 'title' => 'Tune me', 'position' => 0]);

        $client->request('GET', $this->editUrl($forest).'?configure='.$viz->getUuidString());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.modal-title', 'Configure visualization');
        self::assertSelectorExists('.modal form input[name="title"]');
    }

    public function testAddModuleModalActivatesACataloguedModule(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $forest = $this->forestModuleOn(AreaOfInterestFactory::createOne());
        // A catalogue module not yet on this area → appears in the modal with "+ Add".
        ModuleFactory::createOne(['slug' => 'roads', 'name' => 'Roads', 'category' => \App\Composition\Enum\ModuleCategory::Pressure]);

        $crawler = $client->request('GET', $this->editUrl($forest).'?addmodule=1');
        self::assertSelectorTextContains('.modal-title', 'Add a module');
        self::assertSelectorTextContains('.modal-body', 'Roads'); // order-independent: the modal lists Roads

        $token = $crawler->filter('.cat-card form input[name="_token"]')->attr('value');
        $client->request('POST', $this->editUrl($forest).'/add-module', ['_token' => $token, 'module' => 'roads']);

        self::assertResponseRedirects();
        $area = $forest->getArea();
        \assert($area !== null);
        $slugs = array_map(static fn (AreaModule $am): ?string => $am->getModule()?->getSlug(), $this->compositionActive($area));
        self::assertContains('roads', $slugs, 'the catalogued module is now active on the area');
    }

    /**
     * @return list<AreaModule>
     */
    private function compositionActive(\App\Spatial\Entity\AreaOfInterest $area): array
    {
        $repo = static::getContainer()->get(\App\Composition\Repository\AreaModuleRepository::class);
        \assert($repo instanceof \App\Composition\Repository\AreaModuleRepository);

        return $repo->activeForArea($area);
    }

    private function forestModuleOn(object $area): AreaModule
    {
        return AreaModuleFactory::createOne([
            'area' => $area, 'active' => true, 'position' => 1,
            'module' => ModuleFactory::new(['slug' => 'forest', 'name' => 'Forest loss']),
        ]);
    }

    private function editUrl(AreaModule $areaModule): string
    {
        return '/areas/'.$areaModule->getArea()?->getUuidString().'/modules/forest/edit';
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->clear();

        return $em;
    }
}
