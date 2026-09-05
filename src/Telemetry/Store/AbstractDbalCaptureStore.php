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

namespace Uhifadhi\Telemetry\Store;

use Doctrine\DBAL\Connection;
use Uhifadhi\Telemetry\Model\CapturedRequest;
use Uhifadhi\Telemetry\Model\CaptureFilter;
use Uhifadhi\Telemetry\Model\CapturePage;

/**
 * Everything the two SQL sinks share: the one table, the insert, the filtered
 * search, the prune. What differs between Postgres and SQLite is only the DDL and
 * whether the store creates its own schema, so those are the only two hooks the
 * adapters override — the query surface that the admin screen leans on is written
 * once, here, and behaves identically on both.
 *
 * All read/write SQL goes through DBAL's portable query builder and the
 * {@see Connection::insert()}/{@see Connection::delete()} helpers, so a capture
 * hydrates the same value object whichever engine it came from.
 */
abstract class AbstractDbalCaptureStore implements CaptureStore
{
    protected const string TABLE = 'telemetry_capture';

    public function __construct(protected readonly Connection $connection)
    {
    }

    public function store(CapturedRequest $capture): void
    {
        $this->ensureSchema();
        $this->connection->insert(self::TABLE, $capture->toRow());
    }

    public function find(string $id): ?CapturedRequest
    {
        $this->ensureSchema();

        $row = $this->connection->executeQuery(
            'SELECT * FROM '.self::TABLE.' WHERE id = ?',
            [$id],
        )->fetchAssociative();

        return \is_array($row) ? CapturedRequest::fromRow($row) : null;
    }

    public function search(CaptureFilter $filter): CapturePage
    {
        $this->ensureSchema();

        $qb = $this->connection->createQueryBuilder();
        $qb->from(self::TABLE, 't');
        $this->applyConditions($qb, $filter);

        $count = (clone $qb)->select('COUNT(*)')->executeQuery()->fetchOne();
        $total = is_numeric($count) ? (int) $count : 0;

        $qb->select('t.*')
            // Failures first — the ranger's bug is a failure, so it must never be
            // buried under a page of 200s — then most recent within each band.
            ->addOrderBy('CASE WHEN t.status_code >= 400 THEN 0 ELSE 1 END', 'ASC')
            ->addOrderBy('t.captured_at', 'DESC')
            ->setMaxResults(max(1, $filter->limit))
            ->setFirstResult(max(0, $filter->offset));

        $rows = $qb->executeQuery()->fetchAllAssociative();
        $items = array_map(static fn (array $row): CapturedRequest => CapturedRequest::fromRow($row), $rows);

        return new CapturePage($items, $total, $filter->limit, $filter->offset);
    }

    public function prune(\DateTimeImmutable $before): int
    {
        $this->ensureSchema();

        return (int) $this->connection->executeStatement(
            'DELETE FROM '.self::TABLE.' WHERE captured_at < ?',
            [$before->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM)],
        );
    }

    private function applyConditions(\Doctrine\DBAL\Query\QueryBuilder $qb, CaptureFilter $filter): void
    {
        if (null !== $filter->userEmail && '' !== $filter->userEmail) {
            $qb->andWhere('t.user_email = :email')->setParameter('email', $filter->userEmail);
        }
        if (null !== $filter->status) {
            $qb->andWhere('t.status_code = :status')->setParameter('status', $filter->status);
        }
        if ($filter->failuresOnly) {
            $qb->andWhere('t.status_code >= 400');
        }
        if (null !== $filter->endpoint && '' !== $filter->endpoint) {
            $qb->andWhere('t.path LIKE :endpoint')->setParameter('endpoint', '%'.$filter->endpoint.'%');
        }
        if ($filter->since instanceof \DateTimeImmutable) {
            $qb->andWhere('t.captured_at >= :since')
                ->setParameter('since', $filter->since->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM));
        }
        if ($filter->until instanceof \DateTimeImmutable) {
            $qb->andWhere('t.captured_at <= :until')
                ->setParameter('until', $filter->until->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM));
        }
    }

    /**
     * Create the table if this sink owns its own schema. Postgres does not — its
     * schema is a migration run at deploy — so its override is a no-op.
     */
    abstract protected function ensureSchema(): void;
}
