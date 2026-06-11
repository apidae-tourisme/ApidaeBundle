<?php

namespace ApidaeTourisme\ApidaeBundle\Command;

use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use ApidaeTourisme\ApidaeBundle\Config\TachesStatus;
use ApidaeTourisme\ApidaeBundle\Services\TachesServices;
use ApidaeTourisme\ApidaeBundle\Repository\TacheRepository;

/**
 * Cette commande permet de lancer les tâches en attente
 * Elle ne lancera les tâches qu'une par une, en prenant la plus ancienne au statut TO_RUN
 * En lançant cette commande par une tâche cron (toutes les minutes par exemple) ou via le Symfony Scheduler
 * (messenger:consume scheduler_taches) on s'assure d'avoir un traitement régulier des tâches
 * Elle effectue LOOP(10) boucles avec un interval minimal (sleep) de SLEEPTIME(6) secondes à chaque lancement, donc peut durer plus d'une minute.
 * Elle peut lancer des tâches TO_RUN même si d'autres sont déjà en cours : elle n'en lancera au maximum que MAX_TACHES en même temps.
 * Il peut donc y avoir un recouvrement entre les tâches cron si on les déclenche à 1 min d'intervalle :
 *  ce n'est pas un problème puisque chaque commande vérifiera qu'on n'a pas plus de MAX_TACHES lancées.
 */
#[AsCommand(name: 'apidae:tachesManager:start', description: 'Commande destinée à traiter les tâches en attente (1 par 1) : destinée à être utilisée en cron')]
class TachesManagerCommand extends Command
{
    protected LoggerInterface $logger;

    public function __construct(
        protected LoggerInterface $tachesLogger,
        protected EntityManagerInterface $entityManager,
        protected Filesystem $filesystem,
        protected TacheRepository $tacheRepository,
        protected TachesServices $tachesServices,
        protected int $APIDAEBUNDLE_TACHES_SLEEP,
        protected int $APIDAEBUNDLE_TACHES_LOOP,
        protected int $APIDAEBUNDLE_TACHES_MAX
    ) {
        parent::__construct();
    }

    /**
     * Est-ce qu'on exécute la tâche dans ce processus ?
     *  Pour :
     *  Contre :
     *      Si la tâche plante, elle fait planter le gestionnaire
     *      On se retrouve avec 2 façons différentes d'exécuter une même tâche
     *
     * Ou est-ce qu'on lance un processus apidaebundle:tache:run ?
     *  Pour :
     *      1 process par tâche
     *  Contre :
     *
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->tachesLogger->debug('Starting '.self::getDefaultName()) ;

        /**
         * @var array<Process> $childs
         */
        $childs = [] ;

        for ($i = 1 ; $i <= $this->APIDAEBUNDLE_TACHES_LOOP ; $i++) {
            $this->tachesServices->monitorRunningTasks() ;
            $running = $this->tacheRepository->getTachesNumberByStatus('RUNNING') ;

            if ($running >= $this->APIDAEBUNDLE_TACHES_MAX) {
                $this->tachesLogger->debug($running . '/'.$this->APIDAEBUNDLE_TACHES_MAX.' tâches sont déjà en cours : aucune autre tâche ne sera lancée') ;
            } else {
                $next = $this->tacheRepository->claimNextTache();

                if ($next) {
                    $this->tachesLogger->info('Une tâche en attente va être exécutée', [
                        'command' => self::getDefaultName(),
                        'id' => $next->getId(),
                        'tache' => $next->getMethod()
                    ]) ;
                    try {
                        $childs[] = $this->tachesServices->startByProcess($next) ;
                    } catch (Exception $e) {
                        $this->tachesLogger->error($e->getMessage()) ;
                        $next->setStatus(TachesStatus::TO_RUN);
                        $this->tachesServices->save($next);
                    }
                } else {
                    $this->tachesLogger->debug('Aucune tâche en attente n\'a été trouvée') ;
                }
            }
            if ($i != $this->APIDAEBUNDLE_TACHES_LOOP) {
                sleep($this->APIDAEBUNDLE_TACHES_SLEEP) ;
            }
        }

        if (sizeof($childs) > 0) {
            $this->tachesLogger->debug('Cycle terminé... en attente de retour des process enfants') ;
            foreach ($childs as $process) {
                if ($process) {
                    $process->wait(function ($type, $buffer) {
                    });
                }
            }
            $this->tachesLogger->debug('Tous les process enfants ont été terminés') ;
        }

        return Command::SUCCESS;
    }
}
