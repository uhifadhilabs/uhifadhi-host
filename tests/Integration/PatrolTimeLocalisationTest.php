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

namespace Uhifadhi\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Viewer-timezone patrol times (Route A: store UTC, format in the browser).
 *
 * The host overrides the patrol module's shared time macros
 * (templates/bundles/UhifadhiPatrolBundle/_ui.html.twig and dashboard/_ui.html.twig)
 * so every human-facing patrol timestamp leaves the server as a machine
 * <time datetime="…"> carrying the UTC instant, with the existing human string as its
 * inner (no-JS) fallback. The browser-zone conversion itself is the host's `localtime`
 * Stimulus controller and is verified in the browser; what is pinned HERE is the server
 * contract the controller depends on — that the override resolves and emits the machine
 * <time datetime> around the same human render, in UTC.
 */
final class PatrolTimeLocalisationTest extends KernelTestCase
{
    private function twig(): Environment
    {
        self::bootKernel();
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        return $twig;
    }

    #[DataProvider('detailMacros')]
    public function testDetailStampMacrosWrapTheUtcInstantInAMachineTimeElement(
        string $file,
        string $call,
        string $humanFallback,
    ): void {
        $d = new \DateTimeImmutable('2026-08-22 06:10:00', new \DateTimeZone('UTC'));

        $out = $this->twig()
            ->createTemplate("{% import '$file' as ui %}{{ $call }}")
            ->render(['d' => $d]);

        // The machine instant is emitted in UTC (date('c','UTC') → …+00:00).
        self::assertStringContainsString('<time datetime="2026-08-22T06:10:00+00:00">', $out);
        self::assertStringContainsString('</time>', $out);
        // …around the unchanged human render, which stays as the no-JS fallback.
        self::assertStringContainsString($humanFallback, $out);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function detailMacros(): iterable
    {
        yield 'stamp (date · time)' => ['@UhifadhiPatrol/_ui.html.twig', 'ui.stamp(d)', 'sat 22 aug · 06:10'];
        yield 'log_stamp (time · date)' => ['@UhifadhiPatrol/_ui.html.twig', 'ui.log_stamp(d)', '06:10 · 22 aug'];
    }

    public function testTheDashboardStartedMacroWrapsTheUtcInstant(): void
    {
        $d = new \DateTimeImmutable('2026-08-22 06:10:00', new \DateTimeZone('UTC'));

        $out = $this->twig()
            ->createTemplate("{% import '@UhifadhiPatrol/dashboard/_ui.html.twig' as ui %}{{ ui.started(p) }}")
            ->render(['p' => ['startedAt' => $d]]);

        self::assertStringContainsString('<time datetime="2026-08-22T06:10:00+00:00">', $out);
        self::assertStringContainsString('sat 22 aug · 06:10', $out);
    }

    public function testAnEmptyValueStaysADashAndEmitsNoTimeElement(): void
    {
        $out = $this->twig()
            ->createTemplate("{% import '@UhifadhiPatrol/_ui.html.twig' as ui %}{{ ui.stamp(null) }}")
            ->render([]);

        self::assertStringNotContainsString('<time', $out);
        self::assertStringContainsString('—', $out);
    }
}
