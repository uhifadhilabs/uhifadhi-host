<?php

declare(strict_types=1);

namespace App\Tests\Functional\Dashboard;

use App\Spatial\Repository\AreaOfInterestRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zenstruck\Foundry\Test\Factories;

/**
 * The boundary upload: any-GIS-format file in, AreaOfInterest out (or a
 * user-facing error).
 */
final class AreaUploadTest extends WebTestCase
{
    use Factories;

    private function uploadFile(string $name, string $content): UploadedFile
    {
        // BrowserKit does not carry the client filename through submitForm, so
        // the tmp file itself must bear the extension the service routes on.
        $path = tempnam(sys_get_temp_dir(), 'upload').'.'.pathinfo($name, \PATHINFO_EXTENSION);
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, test: true);
    }

    public function testUploadingAGeoJsonBoundaryCreatesTheArea(): void
    {
        $client = static::createClient();

        $client->request('GET', '/areas/new');
        self::assertResponseIsSuccessful();

        $client->submitForm('Import boundary', [
            'area_upload[name]' => 'Uploaded area',
            'area_upload[boundaryFile]' => $this->uploadFile('boundary.geojson', (string) json_encode([
                'type' => 'Feature',
                'properties' => [],
                'geometry' => [
                    'type' => 'Polygon',
                    'coordinates' => [[[35.2, -3.8], [35.8, -3.8], [35.8, -3.2], [35.2, -3.2], [35.2, -3.8]]],
                ],
            ])),
        ]);

        $area = static::getContainer()->get(AreaOfInterestRepository::class)->findOneBy(['name' => 'Uploaded area']);
        self::assertNotNull($area);
        self::assertSame('upload', $area->getSource());
        self::assertResponseRedirects('/areas/'.$area->getId());
    }

    public function testAnOversizedUploadDroppedByPhpIsReportedNotSwallowed(): void
    {
        $client = static::createClient();

        // When a POST exceeds post_max_size, PHP delivers an EMPTY request and
        // the form never registers as submitted — the page must still answer
        // 422 with a human explanation, never a silent 200 (Turbo requires it).
        $client->request('POST', '/areas/new');

        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('form', 'upload limit');
    }

    public function testAGeometrylessFileShowsAFriendlyFormError(): void
    {
        $client = static::createClient();

        $client->request('GET', '/areas/new');
        $client->submitForm('Import boundary', [
            'area_upload[name]' => 'Broken upload',
            'area_upload[boundaryFile]' => $this->uploadFile('attributes.csv', "name,area\nx,1\n"),
        ]);

        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('form', 'polygon');
        self::assertNull(static::getContainer()->get(AreaOfInterestRepository::class)->findOneBy(['name' => 'Broken upload']));
    }
}
