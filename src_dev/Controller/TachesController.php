<?php

namespace App\Controller ;

use ApidaeTourisme\ApidaeBundle\Entity\Tache;
use ApidaeTourisme\ApidaeBundle\Services\TachesServices;
use ApidaeTourisme\ApidaeBundle\Repository\TacheRepository;
use Symfony\Component\Process\Process;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Exception\InvalidParameterException;

class TachesController extends AbstractController
{
    #[Route('/taches', name: 'app_taches')]
    public function demo(Request $request, TacheRepository $tacheRepository, TachesServices $tachesServices)
    {
        $action = $request->get('action') ;
        $tache = null ;

        if ($action == 'creer_tache') {
            $parametres = [
                'var' => 'value1',
                'var2' => ['a','b','c']
            ] ;

            $tache = new Tache() ;
            $tache->setTache('App\\Services\\DemoService::demo') ;
            $tache->setParametres($parametres) ;
            //$tache->setFichier($data['fichier']) ;
            //$tache->setParametresCaches'(['tokenSSO' => $user->getApidaeToken()]) ;
            $tache_id = $tachesServices->add($tache);
        }

        $taches = $tacheRepository->findAll() ;
        return $this->render('demo/taches.html.twig', ['taches' => $taches, 'tache' => $tache]) ;
    }
}