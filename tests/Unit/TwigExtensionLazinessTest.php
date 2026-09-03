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

namespace Uhifadhi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Twig\Extension\ExtensionInterface;
use Uhifadhi\Navigation\AreaShellSource;
use Uhifadhi\Navigation\HostNavigation;

/**
 * BUILD-TIME CANARY. `php bin/console asset-map:compile` runs in the Docker image build, where
 * there is no DATABASE_URL — and it instantiates the whole `twig` service, because UX Icons
 * warms its icon cache from a listener on the asset-compile event. A Twig EXTENSION is
 * constructed eagerly with that service; only a Twig RUNTIME is constructed lazily, on the first
 * call of the function that needs it.
 *
 * So an extension that takes a Doctrine repository drags the DBAL connection into the image
 * build and the build dies on `Environment variable not found: "DATABASE_URL"`. Data access
 * belongs behind a runtime. This test states that rule for everything this application ships
 * into Twig.
 */
final class TwigExtensionLazinessTest extends TestCase
{
    public function testNoTwigExtensionThisApplicationShipsDependsOnTheDatabase(): void
    {
        $offenders = [];

        foreach (glob(__DIR__.'/../../src/Twig/*Extension.php') ?: [] as $file) {
            /** @var class-string $class */
            $class = 'Uhifadhi\\Twig\\'.basename($file, '.php');

            foreach ((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
                $type = $parameter->getType();
                $name = $type instanceof \ReflectionNamedType ? $type->getName() : '';

                if (str_starts_with($name, 'Doctrine\\') || str_starts_with($name, 'Uhifadhi\\Repository\\')) {
                    $offenders[] = $class.'::$'.$parameter->getName().': '.$name;
                }
            }
        }

        self::assertSame([], $offenders, \sprintf(
            "These eagerly-built Twig extensions take a database dependency:\n%s\nMove the data "
            .'access behind a Twig runtime (RuntimeExtensionInterface) so the image build, which '
            .'has no DATABASE_URL, never constructs it.',
            implode("\n", $offenders),
        ));
    }

    /**
     * THE SIDEBAR'S DATA IS STILL LAZY, and it is worth pinning even though the Twig extension
     * that used to carry it is gone.
     *
     * The sidebar now reaches Twig through uhifadhi/shell-module: this application's two seam
     * implementations are plain services, collected by the shell behind ITS lazy runtime. That
     * keeps the same promise by a different route — but only for as long as nobody registers one
     * of them as an extension to "make it available in templates", which would put four
     * repositories back into the image build.
     *
     * @return iterable<string, array{class-string}>
     */
    public static function seamImplementations(): iterable
    {
        yield 'HostNavigation' => [HostNavigation::class];
        yield 'AreaShellSource' => [AreaShellSource::class];
    }

    /**
     * @param class-string $class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('seamImplementations')]
    public function testTheShellsSeamsAreFilledByPlainServicesRatherThanByExtensions(string $class): void
    {
        self::assertFalse(
            (new \ReflectionClass($class))->implementsInterface(ExtensionInterface::class),
            $class.' reads the database and must never be a Twig extension: extensions are built '
            .'eagerly with the twig service, including during the image build that has no database.',
        );
    }
}
