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

namespace Uhifadhi\Tests\Unit\Telemetry;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Uhifadhi\Telemetry\Command\PruneCapturesCommand;
use Uhifadhi\Telemetry\Model\CapturedRequest;
use Uhifadhi\Telemetry\Model\FileMetadata;
use Uhifadhi\Telemetry\Store\SqliteCaptureStore;

/**
 * The retention command: keep the monitor a monitor, not an archive.
 */
final class PruneCapturesCommandTest extends TestCase
{
    public function testPrunesCapturesOlderThanTheTtlAndReportsTheCount(): void
    {
        $store = new SqliteCaptureStore(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $store->store($this->capture('old', (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-60 days')));
        $store->store($this->capture('recent', new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));

        $tester = new CommandTester(new PruneCapturesCommand($store, 30));
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('1', $tester->getDisplay()); // one pruned
        self::assertNull($store->find('old'));
        self::assertNotNull($store->find('recent'));
    }

    public function testDaysOptionOverridesTheDefaultTtl(): void
    {
        $store = new SqliteCaptureStore(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $store->store($this->capture('d10', (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-10 days')));

        $tester = new CommandTester(new PruneCapturesCommand($store, 30));
        $tester->execute(['--days' => '7']); // 10-day-old row now falls outside the window

        self::assertNull($store->find('d10'));
    }

    private function capture(string $id, \DateTimeImmutable $at): CapturedRequest
    {
        return new CapturedRequest(
            $id, $at, 'POST', '/api/patrols', [], [], '{}', false, [new FileMetadata('f', null, 0, null, null)],
            200, '{}', false, 1, null, null, null,
        );
    }
}
