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

/**
 * BUILD-TIME CANARY. `php bin/console asset-map:compile` runs in the Docker image build, where
 * there is no DATABASE_URL — and it instantiates the whole `twig` service, because UX Icons
 * warms its icon cache from a listener on the asset-compile event. A Twig EXTENSION is
 * constructed eagerly with that service; only a Twig RUNTIME is constructed lazily, on the first
 * call of the function that needs it.
 *
 * So an extension that takes a Doctrine repository drags the DBAL connection into image build
 * and the build dies on `Environment variable not found: "DATABASE_URL"`. Data access belongs in
 * a runtime. This test states that rule for every extension the host ships.
 */
final class TwigExtensionLazinessTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string}>
     */
    public static function extensionProvider(): iterable
    {
        foreach (glob(__DIR__.'/../../src/Twig/*Extension.php') ?: [] as $file) {
            /** @var class-string $class */
            $class = 'Uhifadhi\\Twig\\'.basename($file, '.php');

            yield basename($file, '.php') => [$class];
        }
    }

    /**
     * @param class-string $class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('extensionProvider')]
    public function testAnExtensionNeverDependsOnTheDatabase(string $class): void
    {
        $parameters = (new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [];

        $database = [];
        foreach ($parameters as $parameter) {
            $type = $parameter->getType();
            $name = $type instanceof \ReflectionNamedType ? $type->getName() : '';

            if (str_starts_with($name, 'Doctrine\\') || str_starts_with($name, 'Uhifadhi\\Repository\\')) {
                $database[] = '$'.$parameter->getName().': '.$name;
            }
        }

        self::assertSame([], $database, \sprintf(
            'The eagerly-built Twig extension %s takes a database dependency. Move the data '
            .'access to a Twig runtime (RuntimeExtensionInterface) so the image build, which '
            .'has no DATABASE_URL, never constructs it.',
            $class,
        ));
    }

    /**
     * The sidebar's own shape: the extension declares the function, the runtime does the work.
     */
    public function testTheSidebarFunctionIsServedByALazyRuntime(): void
    {
        $functions = (new \Uhifadhi\Twig\SidebarExtension())->getFunctions();

        self::assertCount(1, $functions);
        self::assertSame('sidebar_nav', $functions[0]->getName());
        self::assertSame(
            [\Uhifadhi\Twig\SidebarRuntime::class, 'build'],
            $functions[0]->getCallable(),
        );
        self::assertTrue(
            (new \ReflectionClass(\Uhifadhi\Twig\SidebarRuntime::class))
                ->implementsInterface(\Twig\Extension\RuntimeExtensionInterface::class),
        );
    }
}
