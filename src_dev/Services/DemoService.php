<?php

namespace App\Services ;

use Psr\Log\LoggerInterface;
use ApidaeTourisme\ApidaeBundle\Entity\Tache;
use ApidaeTourisme\ApidaeBundle\Config\TachesCode;
use ApidaeTourisme\ApidaeBundle\Services\TachesServices;

class DemoService
{
    public function __construct(
        private LoggerInterface $tachesLogger,
        private TachesServices $tachesServices
    ) {
    }
    /**
     * Exemple type de tâche lancée par le gestionnaire de tâche :
     * apidae:tache:run X
     */
    public static function demo(Tache $tache): TachesCode
    {
        $steps = 99 ;
        $logger_context = ['id' => $tache->getId(), 'steps' => $steps] ;
        $step = 1 ;
        do {
            $logger_context['step'] = $step ;
            $tache->log('info', 'Début de l\'étape '.$step.'...');
            /**
             * @todo comme l'exemple est static, on n'a pas accès au logger instancié
             * Montrer ici comment récupérer un logger du container
             */
            //$this->logger->info('Début de l\'étape...', $logger_context) ;
            // Do whatever this task has to do
            sleep(5) ;
            $step++ ;
            $tache->log('info', 'Fin de l\'étape '.$step.'...');
            //$this->tachesServices->save($tache) ;
            //$this->logger->info('Etape terminée !', $logger_context) ;
        } while ($step <= $steps) ;

        return TachesCode::SUCCESS ;
    }

    /**
     * @todo Montrer un exemple de tâche utilisant une méthode non statique
    */
    public function demo2(Tache $tache): TachesCode
    {
        $steps = 20 ;
        $logger_context = ['id' => $tache->getId(), 'steps' => $steps] ;
        $logger_context['tachePid'] = $tache->getPid() ;
        $step = 0 ;
        do {
            $step++ ;
            $logger_context['getmypid'] = getmypid() ;
            $logger_context['step'] = $step ;
            $this->tachesLogger->info('Début de l\'étape...', $logger_context) ;
            $tache->log('info', 'Début de l\'étape '.$step.'...'.json_encode($logger_context));
            $this->tachesServices->save($tache) ;
            // Do whatever this task has to do
            sleep(2) ;

            $this->tachesLogger->info('Fin de l\'étape...', $logger_context) ;
            $tache->log('info', 'Fin de l\'étape '.$step.'...');
            $this->tachesServices->save($tache) ;
        } while ($step <= $steps) ;

        return TachesCode::SUCCESS ;
    }
}
