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

use Uhifadhi\Factory\AreaOfInterestFactory;

/**
 * THE INCIDENT FILING FORM, as this deployment renders it.
 *
 * The module ships a third section — "People & evidence", four sub-rows of CAN
 * WAIT pills and a "Then, on the record" explainer — and this host does not want
 * it: the discipline it teaches is unchanged, but a filer at the roadside should
 * be told it in ONE quiet line beside the File control rather than a section that
 * costs a screen. The section is removed by host-side template overrides in
 * templates/bundles/UhifadhiIncidentBundle/report/, and this test pins both
 * halves of that: the line is there, and the section's markup is not.
 */
final class IncidentReportFormTest extends AuthenticatedWebTestCase
{
    private const string QUIET_LINE = 'Only the kind, what happened and where are needed now. Severity, people, evidence and money are added on the record afterwards.';

    public function testTheFormCarriesTheQuietLineInsteadOfThePeopleAndEvidenceSection(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne(['name' => 'Filing area']);

        $client->request('GET', '/areas/'.$area->getUuidString().'/modules/incidents/new');

        self::assertResponseIsSuccessful();

        // ONE line, in the form and beside the File control.
        self::assertSelectorTextContains('.ro-form', self::QUIET_LINE);

        // And the section it replaces is gone — heading, sub-rows and pills.
        self::assertSelectorNotExists('.ro-later');
        self::assertStringNotContainsString('People &amp; evidence', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('Then, on the record', (string) $client->getResponse()->getContent());
    }
}
