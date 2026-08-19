<?php

declare(strict_types=1);

namespace Uhifadhi\Statistics\MessageHandler;

use Uhifadhi\Spatial\Repository\AreaOfInterestRepository;
use Uhifadhi\Statistics\Message\RefreshSynthesis;
use Uhifadhi\Statistics\Service\SynthesisService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RefreshSynthesisHandler
{
    public function __construct(
        private AreaOfInterestRepository $areas,
        private SynthesisService $synthesis,
    ) {
    }

    public function __invoke(RefreshSynthesis $message): void
    {
        $area = $this->areas->find($message->areaId);
        if (null !== $area) {
            $this->synthesis->refresh($area);
        }
    }
}
