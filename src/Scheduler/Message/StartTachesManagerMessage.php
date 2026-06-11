<?php

namespace ApidaeTourisme\ApidaeBundle\Scheduler\Message;

/**
 * Message planifié qui déclenche un cycle du gestionnaire de tâches.
 * Le handler lance apidae:tachesManager:start en sous-processus détaché,
 * ce qui reproduit le recouvrement des exécutions cron.
 */
final class StartTachesManagerMessage
{
}
