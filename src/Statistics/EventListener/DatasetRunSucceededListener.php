<?php

declare(strict_types=1);

namespace App\Statistics\EventListener;

use App\Ingestion\Entity\DatasetRun;
use App\Statistics\Message\RefreshSynthesis;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The R deck's Q6 "next step" made real: every succeeded module run re-derives the area's
 * synthesis, so the scorecard is always current instead of a one-off snapshot. Dispatched
 * async (never a nested flush); statistics' own writes create no DatasetRun, so no loop.
 */
#[AsEntityListener(event: Events::postUpdate, method: 'postUpdate', entity: DatasetRun::class)]
final readonly class DatasetRunSucceededListener
{
    public function __construct(
        private MessageBusInterface $bus,
    ) {
    }

    public function postUpdate(DatasetRun $run): void
    {
        $areaId = $run->getAoi()?->getId();
        if (DatasetRun::STATUS_SUCCEEDED === $run->getStatus() && null !== $areaId) {
            $this->bus->dispatch(new RefreshSynthesis((int) $areaId));
        }
    }
}
