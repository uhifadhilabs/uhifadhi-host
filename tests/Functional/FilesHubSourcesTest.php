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
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Factory\AreaOfInterestFactory;
use UhifadhiLabs\Patrol\Entity\Observation;
use UhifadhiLabs\Patrol\Entity\ObservationPhoto;
use UhifadhiLabs\Patrol\Entity\Patrol;

/**
 * THE FILES HUB IS STOCKED BY THE MODULES, and this is the host's proof of it.
 *
 * /files ships in uhifadhi/storage-module and knows nothing about
 * observations or incidents: everything on it was handed over by a module
 * through a service tagged "storage.file_source". That seam is invisible when it
 * breaks — an untagged source is indistinguishable from a module nobody
 * installed, and the hub simply renders one heading fewer with a cheerful empty
 * state. Only the assembled application can show that the modules this
 * deployment installs are actually reaching it, so only the host can test it.
 *
 * The module-side mappings are pinned in each module's own suite
 * (PatrolFileSourceTest, IncidentFileSourceTest); what is asserted here is
 * strictly the wiring.
 */
final class FilesHubSourcesTest extends AuthenticatedWebTestCase
{
    /**
     * Both installed modules announce themselves — a module holding nothing is
     * still listed, because "we have that and it is empty" is a different fact
     * from "we do not have it".
     */
    public function testEveryInstalledModuleReachesTheHub(): void
    {
        $client = static::createClient();
        $this->loginAs($client);

        $client->request('GET', '/files');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Patrols');
        self::assertSelectorTextContains('body', 'Incidents');
    }

    /**
     * A stored photograph reaches the hub carrying its OWNER — the whole model
     * in one assertion. A file is owner-bound: a tile with no record on it would
     * be a lie about what a file is on this platform.
     */
    public function testAPatrolPhotographAppearsOnTheHubWithTheObservationItBelongsTo(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $observation = $this->aPhotographedObservation();

        $client->request('GET', '/files');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $observation->getRef());
    }

    private function aPhotographedObservation(): Observation
    {
        $area = AreaOfInterestFactory::createOne();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $patrol = new Patrol($area, 'foot');
        $patrol->setStartedAt(new \DateTimeImmutable('2026-08-19 05:30:00'));
        $em->persist($patrol);

        $observation = new Observation($patrol, 'maintenance');
        $em->persist($observation);

        $photo = new ObservationPhoto(
            $observation,
            Uuid::v7(),
            'patrol/'.$patrol->getUuid()->toRfc4122().'/e77c.jpg',
        );
        $photo->setMimeType('image/jpeg')->setByteSize(204_800)
            ->setTakenAt(new \DateTimeImmutable('2026-08-19 06:41:00'));
        $em->persist($photo);

        $em->flush();

        return $observation;
    }
}
