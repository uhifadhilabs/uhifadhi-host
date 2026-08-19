<?php

declare(strict_types=1);

namespace Uhifadhi\Dashboard\Service;

use Uhifadhi\Ingestion\Entity\Dataset;

/**
 * Presents a generic {@see Dataset} for the R-style dataframe viewer and the Explore statistics:
 * infers each column's type (chr / int / dbl) from its values — the store keeps rows untyped — and
 * computes describe() over the numeric columns. Pure read-only transforms; no persistence.
 */
final readonly class DatasetPresenter
{
    /**
     * The column type badges, one per column, inferred from the data: a column whose non-null values
     * are all whole numbers is `int`, all-numeric is `dbl`, otherwise `chr`.
     *
     * @return list<string>
     */
    public function types(Dataset $dataset): array
    {
        $columns = $dataset->getColumns() ?? [];
        $rows = $dataset->getRows() ?? [];
        $types = [];
        foreach (array_keys($columns) as $i) {
            $values = array_filter(array_map(static fn (array $row): mixed => $row[$i] ?? null, $rows), static fn ($v): bool => null !== $v);
            if ([] === $values || !array_all($values, static fn ($v): bool => \is_int($v) || \is_float($v))) {
                $types[] = 'chr';
            } elseif (array_all($values, static fn ($v): bool => \is_int($v) || (\is_float($v) && floor($v) === $v))) {
                $types[] = 'int';
            } else {
                $types[] = 'dbl';
            }
        }

        return $types;
    }

    /**
     * The names of the numeric (int/dbl) columns, in order.
     *
     * @return list<string>
     */
    public function numericColumns(Dataset $dataset): array
    {
        $columns = $dataset->getColumns() ?? [];
        $types = $this->types($dataset);
        $numeric = [];
        foreach ($columns as $i => $name) {
            if ('chr' !== $types[$i]) {
                $numeric[] = (string) $name;
            }
        }

        return $numeric;
    }

    /**
     * A describe() row per numeric column: count, mean, population std, min, median, max — the
     * summary the Explore tab tabulates. Non-numeric columns are skipped.
     *
     * @return list<array{column: string, count: int, mean: float, std: float, min: float, median: float, max: float}>
     */
    public function describe(Dataset $dataset): array
    {
        $columns = $dataset->getColumns() ?? [];
        $rows = $dataset->getRows() ?? [];
        $types = $this->types($dataset);

        $out = [];
        foreach ($columns as $i => $name) {
            if ('chr' === $types[$i]) {
                continue;
            }
            $values = [];
            foreach ($rows as $row) {
                $v = $row[$i] ?? null;
                if (\is_int($v) || \is_float($v)) {
                    $values[] = (float) $v;
                }
            }
            if ([] === $values) {
                continue;
            }
            $out[] = ['column' => (string) $name, ...$this->summarize($values)];
        }

        return $out;
    }

    /**
     * @param list<float> $values non-empty
     *
     * @return array{count: int, mean: float, std: float, min: float, median: float, max: float}
     */
    private function summarize(array $values): array
    {
        $n = \count($values);
        $mean = array_sum($values) / $n;
        $variance = array_sum(array_map(static fn (float $v): float => ($v - $mean) ** 2, $values)) / $n;
        sort($values);
        $mid = intdiv($n, 2);
        $median = 0 === $n % 2 ? ($values[$mid - 1] + $values[$mid]) / 2 : $values[$mid];

        return [
            'count' => $n,
            'mean' => $mean,
            'std' => sqrt($variance),
            'min' => $values[0],
            'median' => $median,
            'max' => $values[$n - 1],
        ];
    }
}
