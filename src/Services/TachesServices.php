<?php

namespace ApidaeTourisme\ApidaeBundle\Services;

use Exception;
use ReflectionMethod;
use Psr\Log\LoggerInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\Process\Process;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Filesystem\Filesystem;
use ApidaeTourisme\ApidaeBundle\ApidaeUser;
use ApidaeTourisme\ApidaeBundle\Entity\Tache;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\HttpKernel\KernelInterface;
use ApidaeTourisme\ApidaeBundle\Command\TacheCommand;
use ApidaeTourisme\ApidaeBundle\Command\TachesManagerCommand;
use ApidaeTourisme\ApidaeBundle\Config\TachesCode;
use ApidaeTourisme\ApidaeBundle\Config\TachesStatus;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use ApidaeTourisme\ApidaeBundle\Repository\TacheRepository;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\Routing\Exception\InvalidParameterException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class TachesServices
{
    protected string $dossierTaches;
    protected LoggerInterface $logger ;
    protected ContainerInterface $container ;

    public const FICHIERS_EXTENSIONS = ['xlsx', 'ods'];
    public const FICHIERS_MIMES = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.oasis.opendocument.spreadsheet',
    ];

    public function __construct(
        protected EntityManagerInterface $em,
        protected TacheRepository $tacheRepository,
        protected LoggerInterface $tachesLogger,
        protected Security $security,
        protected KernelInterface $kernel,
        protected ParameterBagInterface $params,
        protected Filesystem $filesystem,
        protected SluggerInterface $slugger,
        protected int $APIDAEBUNDLE_TACHES_MONITOR_GRACE,
    ) {
        $this->dossierTaches = $this->kernel->getProjectDir() . $this->params->get('apidaebundle.task_folder') ;
        $this->container = $kernel->getContainer() ;
    }

    public function getDossierTaches()
    {
        return $this->dossierTaches;
    }

    /**
     * Ajoute une tâche TO_RUN en bdd
     */
    /**
     * @param array<string, mixed>|null $params
     */
    public function add(Tache $tache, ?array $params = null): int|false
    {
        if (!isset($params['userEmail'])) {
            /** @var ApidaeUser|null $user */
            $user = $this->security->getUser();
            if ($user === null) {
                throw new \RuntimeException('Utilisateur non authentifié');
            }
            $tache->setUserEmail($user->getEmail());
        } else {
            $tache->setUserEmail($params['userEmail']);
        }

        if (isset($params['method'])) {
            $tache->setMethod($params['method']);
        }

        if (isset($params['parametres'])) {
            $tache->setParametres($params['parametres']);
        }
        if (isset($params['parametresCaches'])) {
            $tache->setParametresCaches($params['parametresCaches']);
        }
        //if (isset($params['status'])) $tache->setStatus($params['status']);
        if (isset($params['result'])) {
            $tache->setResult($params['result']);
        }

        $tache->setCreationdate(new \DateTime());

        $tache->setStatus(TachesStatus::TO_RUN);

        $this->save($tache);

        $id = $tache->getId();

        /**
         * Traitement du fichier :
         * 2 cas : le fichier est déjà créé et déjà mis à sa place.
         */
        if (
            isset($params['fichier'])
            && gettype($params['fichier']) == 'object'
            && get_class($params['fichier']) == 'Symfony\Component\HttpFoundation\File\UploadedFile'
        ) {
            $tachePath = $this->kernel->getProjectDir() .'/'. $this->params->get('apidaebundle.task_folder') . $id . '/';
            try {
                $this->filesystem->remove($tachePath);
                $this->filesystem->mkdir($tachePath, 0777);
            } catch (IOException $e) {
                $this->tachesLogger->error(__METHOD__ . ':' . $e->getMessage());
                return false;
            }

            if (!in_array($params['fichier']->guessExtension(), self::FICHIERS_EXTENSIONS)) {
                $this->tachesLogger->error(__METHOD__ . ': Type de fichier non autorisé');
                return false;
            }
            $originalFilename = pathinfo($params['fichier']->getClientOriginalName(), PATHINFO_FILENAME);
            $filename = $this->slugger->slug($originalFilename) .  '.' . $params['fichier']->guessExtension();

            try {
                $params['fichier']->move(
                    $tachePath,
                    $filename
                );
            } catch (FileException $e) {
                $this->tachesLogger->error(__METHOD__ . ': Déplacement du fichier impossible');
                return false;
            }

            $tache->setFichier($filename);
            $this->save($tache);
        }

        return $id;
    }

    public function restart(Tache $tache)
    {
        $tache->setStatus(TachesStatus::TO_RUN);
        //$tache->setCreationdate(new \DateTime());
        $this->save($tache) ;
    }

    /**
     * Lance le gestionnaire de tâches en sous-processus détaché (depuis le worker scheduler).
     * Utilise nohup + shell background : un Process Symfony non conservé tuerait l'enfant à la destruction.
     */
    public function startManagerInBackground(): void
    {
        $commandLine = $this->buildConsoleProcess(TachesManagerCommand::getDefaultName())->getCommandLine();
        $wrapper = Process::fromShellCommandline(
            'nohup ' . $commandLine . ' > /dev/null 2>&1 &',
            $this->kernel->getProjectDir(),
        );
        $wrapper->run();

        $this->tachesLogger->debug('Gestionnaire de tâches lancé en sous-processus', [
            'command' => TachesManagerCommand::getDefaultName(),
        ]);
    }

    /**
     * Lance une tâche en process (tâche de fond).
     * La tâche doit avoir été réservée via claimNextTache() (statut RUNNING).
     */
    public function startByProcess(Tache $tache, bool $force = false): Process
    {
        if (!$force && $tache->getStatus() != TachesStatus::RUNNING->value) {
            throw new \Exception('La tâche ' . $tache->getId() . ' n\'est pas en état RUNNING (' . $tache->getStatus() . ')');
        }

        $process = $this->buildConsoleProcess(
            TacheCommand::getDefaultName(),
            (string) $tache->getId(),
        );
        $process->start();

        return $process;
    }

    /**
     * Stoppe une tâche par un kill -9.
     * Nécessite pgrep local : à appeler depuis le pod scheduler (pas le pod web).
     */
    public function stop(Tache $tache): array
    {
        /**
         * @todo : voir comment tuer un process à partir du pid
         * @warning : pas sûr que ce soit facile, il faut déjà que l'utilisateur ayant lancé le process soit le même que celui qui lance le stop
         *  et il faut que le processus tourne toujours, sauf qu'il a pu lancer des sous-process et ne plus tourner lui même alors que la tâche n'est pas terminée
         */
        $killable_status = [
            TachesStatus::RUNNING->value
        ];

        if (!$this->isProcessRunning($tache)) {
            return ['error' => 'La tâche ne semble pas être en cours d\'exécution (processus introuvable sur ce pod — arrêt depuis le worker scheduler uniquement)'];
        }

        if (! in_array($tache->getStatus(), $killable_status)) {
            return ['error' => 'La tâche ' . $tache->getId() . ' n\'est pas en état [' . implode(',', $killable_status) . '] (' . $tache->getStatus() . ')'];
        }

        try {
            $pids = $this->getTacheProcessPids($tache);
            if ($pids === []) {
                return ['error' => 'Impossible de trouver le pid de la tâche '.$tache->getId()] ;
            }
            $realPid = $pids[array_key_last($pids)];
        } catch (Exception $e) {
            return ['error' => 'Impossible de récupérer le pid...'.$e->getMessage()];
        }

        try {
            $process = new Process(['kill', '-9', $realPid]);
            $process->run();
            if ($process->getErrorOutput() == "" && $process->getExitCode() == 0) {
                return ['result' => 'ok'];
            } else {
                return ['error' => $process->getErrorOutput()];
            }
        } catch (\Exception $e) {
            return ['error' => 'kill -9 failed...' . $e->getMessage()];
        }
    }

    /**
     * Exécute la tâche
     */
    public function run(Tache $tache): TachesCode
    {
        $this->tachesLogger->info(__METHOD__.'('.$tache->getId().')') ;

        /**
         * @var TachesCode $ret
         */
        $ret = TachesCode::FAILURE ;

        // Méthode non statique : App\Class:method
        if (preg_match("#^([a-zA-Z\\\]+):([a-zA-Z0-9]+)$#", $tache->getMethod(), $match)) {
            /**
             * @see https://www.php.net/manual/en/function.is-callable.php#126199
             * Impossible d'utiliser is_callable ici pour une méthode non statique
             */
            //if (! is_callable([$match[1],$match[2]], false, $callable_name)) {
            if (! method_exists($match[1], $match[2])) {
                $this->tachesLogger->error('Méthode invalide : '.$tache->getMethod()) ;
                return TachesCode::FAILURE ;
            } else {
                $rm = new ReflectionMethod($match[1], $match[2]);
                if ($rm->isStatic()) {
                    $this->tachesLogger->error('Méthode invalide : '.$tache->getMethod().' (la méthode est statique, utilisez :: au lieu de :)') ;
                    return TachesCode::FAILURE ;
                }
            }
            $this->tachesLogger->info(__METHOD__.' : starting : '.lcfirst($match[1]).'->'.$match[2].'(...)') ;
            /**
             * On s'apprète à lancer une méthode sur un service dont on n'a pas connaissance :
             * App\Services\Whatever->method(...)
             * Comme on ne le connait pas il faut l'instancier dynamiquement
             */
            /**
             * @see https://stackoverflow.com/a/65526859
             */

            // read the parameters given in the cmd and decide what class is
            // gona be injected.
            // $service_name = "App\\My\\Namespace\\ServiceClassName"
            $service = $this->container->get($match[1]);
            $ret = $service->{$match[2]}($tache);
        }
        // Méthode statique : App\Class::method
        elseif (preg_match("#^([a-zA-Z\\\]+)::([a-zA-Z]+)$#", $tache->getMethod(), $match)) {
            if (! is_callable([$match[1],$match[2]], false, $callable_name)) {
                $this->tachesLogger->error('Méthode invalide : '.$tache->getMethod().' ('.$callable_name.')') ;
                return TachesCode::FAILURE ;
            } else {
                $rm = new ReflectionMethod($match[1], $match[2]);
                if (! $rm->isStatic()) {
                    $this->tachesLogger->error('Méthode invalide : '.$tache->getMethod().' (la méthode n\'est pas statique, utilisez : au lieu de ::)') ;
                    return TachesCode::FAILURE ;
                }
            }

            $ret = call_user_func([$match[1], $match[2]], $tache);
        } else {
            $this->tachesLogger->error('Impossible d\'exécuter la tâche : la commande '.$tache->getMethod().' est incohérence') ;
        }

        if (!$ret instanceof TachesCode) {
            $this->tachesLogger->error('La méthode '.$tache->getMethod().' n\'a pas renvoyé un TachesCode') ;
            return TachesCode::FAILURE ;
        }

        return $ret;
    }

    /**
     * Vérifie le statut des tâches RUNNING via pgrep (processus local).
     * À appeler uniquement depuis le worker scheduler (même pod que apidae:tache:run).
     * Ne pas appeler depuis le pod web : les PID ne sont pas partagés entre conteneurs/pods K8s.
     */
    public function monitorRunningTasks(): void
    {
        // Récupérer le pid des tâches RUNNING
        // Vérifier si le pid tourne toujours ?
        // Si non : mettre la tâche à INTERRUPTED : la tâche aurait dû passer à COMPLETED
        $taches = $this->tacheRepository->getTachesByStatus(TachesStatus::RUNNING);
        foreach ($taches as $tache) {
            $this->monitorTask($tache) ;
        }
    }

    public function monitorTask(Tache $tache): void
    {
        if ($tache->getStatus() != TachesStatus::RUNNING->value) {
            return;
        }

        if ($this->isProcessRunning($tache)) {
            return;
        }

        $startDate = $tache->getStartDate();
        if ($startDate !== null) {
            $elapsed = time() - $startDate->getTimestamp();
            if ($elapsed < $this->APIDAEBUNDLE_TACHES_MONITOR_GRACE) {
                return;
            }
        }

        $tacheId = $tache->getId();
        $this->tachesLogger->error('monitorTask('.$tacheId.') : task is not running => INTERRUPTED') ;
        $tache->setStatus(TachesStatus::INTERRUPTED);
        $this->save($tache);
    }

    /**
     * Détermine si le process apidae:tache:run de la tâche tourne sur ce pod.
     * Exclut le processus courant pour ne pas confondre avec apidae:tache:run en cours de démarrage.
     */
    public function isProcessRunning(Tache $tache): bool
    {
        return $this->getTacheProcessPids($tache, excludeCurrentProcess: true) !== [];
    }

    /**
     * @return list<int>
     */
    private function getTacheProcessPids(Tache $tache, bool $excludeCurrentProcess = false): array
    {
        $process = Process::fromShellCommandline('pgrep -f ' . escapeshellarg($this->getTacheProcessPgrepPattern($tache)));
        $process->run();
        $output = trim($process->getOutput());
        if ($output === '') {
            return [];
        }

        $excludePid = $excludeCurrentProcess ? getmypid() : null;
        $pids = [];
        foreach (preg_split('/\s+/', $output) ?: [] as $pid) {
            $pid = (int) $pid;
            if ($pid <= 0 || ($excludePid !== false && $pid === $excludePid)) {
                continue;
            }
            $pids[] = $pid;
        }

        return $pids;
    }

    /**
     * Motif pgrep pour le sous-processus apidae:tache:run (options Symfony entre bin/console et la commande).
     */
    private function getTacheProcessPgrepPattern(Tache $tache): string
    {
        return '[b]in/console.*' . preg_quote(TacheCommand::getDefaultName(), '/') . ' ' . (int) $tache->getId() . '$';
    }

    /**
     * @param string ...$args Arguments console après bin/console (commande, args…)
     */
    private function buildConsoleProcess(string ...$args): Process
    {
        // Pas de timeout : le défaut Symfony (60s) tue les longues tâches au wait() du manager → INTERRUPTED.
        $process = new Process(
            array_merge(
                [
                    \PHP_BINARY,
                    $this->kernel->getProjectDir() . '/bin/console',
                    '--env=' . $this->kernel->getEnvironment(),
                    '--no-interaction',
                ],
                $args,
            ),
            $this->kernel->getProjectDir(),
        );
        $process->setTimeout(null);

        return $process;
    }

    public function delete(Tache $tache): bool
    {
        $this->filesystem->remove($this->kernel->getProjectDir() . $this->params->get('apidaebundle.task_folder') . $tache->getId());
        $this->em->remove($tache) ;
        $this->em->flush() ;
        return true;
    }

    /**
     * persist & flush
     */
    public function save(Tache $tache): void
    {
        $this->em->persist($tache);
        $this->em->flush();
        $this->em->refresh($tache) ;
    }
}
