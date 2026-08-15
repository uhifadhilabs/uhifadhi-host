<?php

declare(strict_types=1);

namespace App\Spatial\Module;

/**
 * The Method-tab caption that travels with a module from the analysis prototype: what it measures &
 * answers, the takeaway, the computation pipeline, and the data source with its honest caveats.
 */
final readonly class MethodCaption
{
    /**
     * @param list<array{step: string, detail: string}>  $pipeline
     * @param list<array{label: string, value: string}>  $source
     */
    public function __construct(
        public string $measures,
        public string $answers,
        public string $takeaway,
        public array $pipeline,
        public string $pipelineNote,
        public array $source,
        public string $sourceNote,
    ) {
    }
}
