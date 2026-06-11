<?php

namespace ApidaeTourisme\ApidaeBundle\Scheduler\Handler;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use ApidaeTourisme\ApidaeBundle\Services\TachesServices;
use ApidaeTourisme\ApidaeBundle\Scheduler\Message\StartTachesManagerMessage;

#[AsMessageHandler]
final class StartTachesManagerHandler
{
    private const LOCK_KEY = 'apidae_taches_manager_tick';
    private const LOCK_TTL = 55;

    public function __construct(
        private readonly TachesServices $tachesServices,
        private readonly LockFactory $lockFactory,
    ) {
    }

    public function __invoke(StartTachesManagerMessage $message): void
    {
        $lock = $this->lockFactory->createLock(self::LOCK_KEY, self::LOCK_TTL);

        if (!$lock->acquire()) {
            return;
        }

        try {
            $this->tachesServices->startManagerInBackground();
        } finally {
            $lock->release();
        }
    }
}
