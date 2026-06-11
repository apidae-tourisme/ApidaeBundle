<?php

namespace ApidaeTourisme\ApidaeBundle\Controller ;

use Symfony\Component\Process\Process;
use ApidaeTourisme\ApidaeBundle\Entity\Tache;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use ApidaeTourisme\ApidaeBundle\Config\TachesStatus;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use ApidaeTourisme\ApidaeBundle\Services\TachesServices;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ApidaeTourisme\ApidaeBundle\Repository\TacheRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Exception\InvalidParameterException;

#[Route('/apidaebundle/taches', name: 'apidaebundle_taches_')]
class TacheAjaxController extends AbstractController
{
    #[Route('/start/{id}', name: 'start')]
    public function start(string $id, Request $request, TachesServices $tachesServices, TacheRepository $tacheRepository): JsonResponse
    {
        $tache = $tacheRepository->findOneBy(['id' => $id]);
        $tachesServices->restart($tache) ;
        return new JsonResponse([
            'id' => (int)$tache->getId(),
            //'pid' => $tache->getPid(),
            'startdate' => $tache->getStartDate()
        ]);
    }

    #[Route('/stop/{id}', name: 'stop')]
    public function stop(string $id, TachesServices $tachesServices, TacheRepository $tacheRepository): JsonResponse
    {
        $tache = $tacheRepository->findOneBy(['id' => $id]);
        return new JsonResponse($tachesServices->stop($tache));
    }

    #[Route('/delete/{id}', name: 'delete')]
    public function delete(string $id, TachesServices $tachesServices, TacheRepository $tacheRepository): JsonResponse
    {
        $tache = $tacheRepository->getTacheById($id);
        if (!$tache) {
            return new JsonResponse(['error' => 'Tâche introuvable'], 404) ;
        }

        /**
         * @var User $user
         */
        $user = $this->getUser();
        if ($tache->getUserEmail() !== $user->getEmail()) {
            return new JsonResponse(['error' => 'l\'utilisateur #' . $user->getEmail() . ' ne peut pas supprimer une tâche #' . $id . ' de l\'utilisateur #' . $tache->getUserEmail()], 403) ;
        }

        if ($tachesServices->delete($tache)) {
            return new JsonResponse(['code' => 'SUCCESS']) ;
        }

        return new JsonResponse(['error' => 'UNKNOWN_ERROR'], 500) ;
    }

    #[Route('/monitor', name: 'monitor')]
    public function monitor(Request $request)
    {
        return new JsonResponse(['temp' => 'temp']) ;
    }

    /**
     * Renvoie une représentation json des offres récupérées en bdd
     *
     * @param Request $request
     * @param TacheRepository $tacheRepository
     * @param TachesServices $tachesServices
     * @return void
     */
    #[Route('/statusBy', name: 'statusBy')]
    public function statusBy(Request $request, TacheRepository $tacheRepository)
    {
        $id = (is_int((int)$request->get('id'))) ? $request->get('id') : null ;
        $ids = (is_array($request->get('ids'))) ? $request->get('ids') : null ;
        $signatures = (is_array($request->get('signatures'))) ? $request->get('signatures') : null ;
        $signature = (is_string($request->get('signature'))) ? $request->get('signature') : null ;

        $taches = [] ;
        if ($ids !== null) {
            $taches = $tacheRepository->findBy(['id' => $ids]) ;
        } elseif ($id !== null) {
            $taches = $tacheRepository->findBy(['id' => $id]) ;
        } elseif ($signatures !== null) {
            $taches = $tacheRepository->findBySignatures($signatures) ;
        } elseif ($signature !== null) {
            $taches = $tacheRepository->findBySignatures([$signature]) ;
        }

        if (sizeof($taches) == 0) {
            return new JsonResponse(['code'=>404], 404) ;
        }

        /**
         * @var User $user
         */
        $user = $this->getUser();
        $response = ['taches' => []] ;
        foreach ($taches as $tache) {
            $tache = $tacheRepository->getTacheById($tache->getId()); // refresh depuis la BDD (progression en cours)
            $tmp = $tache->get() ;
            if ($tache->getUserEmail() != $user->getEmail()) {
                unset($tmp['parametresCaches']);
            }

            $tmp['status_html'] = $this->render('taches/status.html.twig', ['tache' => $tache])->getContent() ;

            if (sizeof($tache->getResult()) > 0) {
                $tmp['result_html'] = $this->render('taches/result.html.twig', ['logs' => $tache->getLogs()])->getContent() ;
            } else {
                $tmp['result_html'] = null ;
            }

            $response['taches'][] = $tmp ;
        }

        return new JsonResponse($response);
    }
}
