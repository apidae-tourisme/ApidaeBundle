<?php

namespace ApidaeTourisme\ApidaeBundle\Command;

use Exception;
use Psr\Log\LoggerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use ApidaeTourisme\ApidaeBundle\Config\TachesStatus;
use Symfony\Component\Console\Output\OutputInterface;
use ApidaeTourisme\ApidaeBundle\Services\TachesServices;
use ApidaeTourisme\ApidaeBundle\Repository\TacheRepository;

/**
 * Lance l'exécution d'une tâche définie par son identifiant
 */
#[AsCommand(name: 'apidae:tache:run', description: 'Lance une tâche définie par son identifiant')]
class TacheCommand extends Command
{
    public function __construct(
        private LoggerInterface $tachesLogger,
        protected EntityManagerInterface $entityManager,
        protected Filesystem $filesystem,
        protected TacheRepository $tacheRepository,
        protected TachesServices $tachesServices
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Identifiant de tâche obligatoire');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getArgument('id');
        $tache = $this->tacheRepository->getTacheById($id) ;
        $logger_context = ['command' => self::getDefaultName(), 'id' => $id] ;
        $this->tachesLogger->info(self::getDefaultName().' '.$id, $logger_context) ;

        if (!$tache) {
            $this->tachesLogger->warning('Tâche '.$id.' introuvable...', $logger_context) ;
            return Command::FAILURE ;
        }

        $status = $tache->getStatus();

        if ($status === TachesStatus::RUNNING->value && $this->tachesServices->isProcessRunning($tache)) {
            $this->tachesLogger->warning('Tâche '.$id.' déjà en cours d\'exécution sur ce pod', $logger_context) ;

            return Command::FAILURE;
        }

        if ($status !== TachesStatus::TO_RUN->value && $status !== TachesStatus::RUNNING->value) {
            $this->tachesLogger->warning('Tâche '.$id.' dans un statut non exécutable : '.$status, $logger_context) ;

            return Command::FAILURE;
        }

        $this->tachesLogger->info('Tâche '.$id.' trouvée : lancement de la tâche', $logger_context) ;

        $tache->setStatus(TachesStatus::RUNNING);
        $tache->setStartDate(new \DateTime());
        $tache->setResult([]);
        $tache->setEndDate(null);
        $tache->setProgress(null);
        $pid = getmypid();
        if ($pid !== false) {
            $tache->setPid($pid);
        }
        $this->tachesServices->save($tache) ;

        $commandState = Command::FAILURE;
        $terminalStatus = TachesStatus::FAILED;

        try {
            $retour = $this->tachesServices->run($tache);
            $commandState = $retour->value;
            $terminalStatus = $commandState === Command::SUCCESS
                ? TachesStatus::COMPLETED
                : TachesStatus::FAILED;
        } catch (Exception $e) {
            $this->tachesLogger->error('Sortie de tâche sur une exception... '.$e->getMessage()) ;
            $tache->log('error', 'Sortie de tâche sur une exception... '.$e->getMessage()) ;
            $this->tachesLogger->debug($e->getTraceAsString()) ;
            $tache->log('debug', $e->getTraceAsString()) ;
            $terminalStatus = TachesStatus::INTERRUPTED;
        }

        if ($terminalStatus === TachesStatus::COMPLETED) {
            $nextId = $tache->getTacheSuivante();
            if ($nextId !== null) {
                $next = $this->tacheRepository->getTacheById((int) $nextId);
                if ($next !== null && $next->getStatus() === TachesStatus::WAITING->value) {
                    $next->setStatus(TachesStatus::TO_RUN);
                    $this->tachesServices->save($next);
                }
            }
        }

        $tache->setStatus($terminalStatus);
        $tache->setEndDate(new \DateTime());
        $this->tachesServices->save($tache);

        $this->tachesLogger->info('STATUS:' . $tache->getStatus(), $logger_context);

        return $commandState;
    }
}
