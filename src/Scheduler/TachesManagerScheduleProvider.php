<?php

namespace ApidaeTourisme\ApidaeBundle\Scheduler;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use ApidaeTourisme\ApidaeBundle\Scheduler\Message\StartTachesManagerMessage;

/**
 * Remplace la tâche cron « bin/console apidae:tachesManager:start » toutes les minutes.
 *
 * Le worker scheduler (messenger:consume scheduler_taches) reste léger : chaque tick
 * délègue l'exécution à un sous-processus, ce qui permet le recouvrement entre cycles
 * comme avec cron (plusieurs gestionnaires peuvent tourner en parallèle, la limite
 * APIDAEBUNDLE_TACHES_MAX restant assurée en base).
 * Un lock distribué (StartTachesManagerHandler) évite les ticks dupliqués entre replicas.
 */
#[AsSchedule('taches')]
final class TachesManagerScheduleProvider implements ScheduleProviderInterface
{
    private ?Schedule $schedule = null;

    public function __construct(
        private readonly CacheInterface $cache,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return $this->schedule ??= (new Schedule())
            ->with(
                RecurringMessage::cron('* * * * *', new StartTachesManagerMessage()),
            )
            ->stateful($this->cache);
    }
}
