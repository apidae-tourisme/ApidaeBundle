<?php

namespace ApidaeTourisme\ApidaeBundle\Controller ;

use ApidaeTourisme\ApidaeBundle\Config\TachesStatus;
use Symfony\Component\Process\Process;
use ApidaeTourisme\ApidaeBundle\Entity\Tache;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use ApidaeTourisme\ApidaeBundle\Services\TachesServices;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ApidaeTourisme\ApidaeBundle\Repository\TacheRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Exception\InvalidParameterException;

#[Route('/apidaebundle/taches', name: 'apidaebundle_taches_')]
class TacheController extends AbstractController
{
    public function __construct(
        protected TachesServices $tachesServices,
        protected TacheRepository $tacheRepository,
        protected KernelInterface $kernel
    ) {
    }

    #[Route('/mestaches', name: 'mestaches')]
    public function mestaches()
    {
        /**
         * @var User $user
         */
        $user = $this->getUser();
        $taches = $this->tacheRepository->findBy(['userEmail' => $user->getEmail()]);

        $alerts = [];
        if (!is_writable($this->kernel->getProjectDir() . $this->getParameter('apidaebundle.task_folder'))) {
            $alerts[] = ['type' => 'warning', 'message' => 'Attention, le dossier de stockage des tâches n\'est pas accessible en écriture pour ' . get_current_user()];
        }

        return $this->render('taches/list.html.twig', ['h1' => 'Mes tâches', 'taches' => $taches, 'alerts' => $alerts]);
    }

    #[Route('/manager', name: 'manager')]
    public function manager(Request $request)
    {
        $taches = $this->tacheRepository->findAll();

        if ($request->get('action') == 'cancelRunningTasks') {
            foreach ($taches as $t) {
                if ($t->getStatus() == TachesStatus::RUNNING->value) {
                    $this->tachesServices->stop($t) ;
                } elseif ($t->getStatus() == TachesStatus::TO_RUN->value) {
                    $t->setStatus(TachesStatus::CANCELLED) ;
                    dump($t->getStatus()) ;
                    $this->tachesServices->save($t) ;
                }
            }
        }

        $alerts = [];
        if (!is_writable($this->kernel->getProjectDir() . $this->getParameter('apidaebundle.task_folder'))) {
            $alerts[] = ['type' => 'warning', 'message' => 'Attention, le dossier de stockage des tâches n\'est pas accessible en écriture pour ' . get_current_user()];
        }
        return $this->render('taches/list.html.twig', ['taches' => $taches, 'alerts' => $alerts]);
    }

    #[Route('/download/{id}', name: 'download')]
    public function download(string $id)
    {
        $tache = $this->tacheRepository->getTacheById($id);
        if (!$tache) {
            throw new \Exception('Tâche introuvable');
        } else {
            /**
             * @var User $user
             */
            $user = $this->getUser();
            if ($tache->getUserEmail() != $user->getEmail()) {
                throw new \Exception('Utilisateur ' . $user->getEmail() . ' ne peut pas accéder au fichier de la tâche ' . $id);
            }
        }

        $response = new BinaryFileResponse($this->kernel->getProjectDir() . $this->getParameter('apidaebundle.task_folder') . $tache->getId() . '/' . $tache->getFichier());
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $tache->getFichier()
        );
        return $response;
    }

    #[Route('/status/{id}', name: 'status')]
    public function status(int $id, Request $request)
    {
        $_format = (in_array($request->get('_format'), ['html', 'json'])) ? $request->get('_format') : 'html';
        $tache = $this->tacheRepository->getTacheById($id);
        if (!$tache) {
            if ($_format == 'json') {
                return new JsonResponse(['code'=>404], 404) ;
            } else {
                return new Response('Tache introuvable');
            }
        }
        if ($_format == 'json') {
            $ret = $tache->get();
            /**
             * @var User $user
             */
            $user = $this->getUser();
            if ($tache->getUserEmail() != $user->getEmail()) {
                unset($ret['parametresCaches']);
            }
            return new JsonResponse($ret);
        }

        return $this->render(
            'taches/status.' . $_format . '.twig',
            ['tache' => $tache]
        );
    }

    #[Route('/result/{id}', name: 'result')]
    public function result(string $id)
    {
        $tache = $this->tacheRepository->getTacheById($id);
        if (!$tache) {
            throw new \Exception('Tache introuvable');
        }

        return $this->render(
            'taches/result.html.twig',
            ['tache' => $tache]
        );
    }


    /**
     * @todo : on vérifie que l'utilisateur qui cherche à accéder a les droit sur la tâche ou pas ?
     */
    #[Route('/tache/{id}', name: 'tache')]
    public function tache(string $id)
    {
        $tache = $this->tacheRepository->getTacheById($id);
        if (!$tache) {
            throw new \Exception('Tache introuvable');
        }

        return $this->render(
            'taches/tache.html.twig',
            ['tache' => $tache]
        );
    }
}
