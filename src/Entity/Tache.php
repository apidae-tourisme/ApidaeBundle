<?php

namespace ApidaeTourisme\ApidaeBundle\Entity;

use ApidaeTourisme\ApidaeBundle\Config\TachesStatus;
use ApidaeTourisme\ApidaeBundle\Repository\TacheRepository;
use Doctrine\ORM\Mapping as ORM;
use Psr\Log\LoggerInterface;

#[ORM\Entity(repositoryClass: TacheRepository::class)]
class Tache
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'tache_id_seq', allocationSize: 1)]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $userEmail = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $method = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $fichier = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $parametres = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $parametresCaches = [];

    /**
     * , columnDefinition="enum('TO_RUN', 'RUNNING', 'COMPLETED', 'FAILED', 'INTERRUPTED', CANCELLED')"
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $status = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $result = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $progress = [];

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $startdate = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $enddate = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $creationdate = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $pid = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $signature = null;

    #[ORM\Column(nullable: true)]
    private ?Tache $tacheSuivante = null;

    private LoggerInterface $logger ;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserEmail(): ?string
    {
        return $this->userEmail;
    }

    public function setUserEmail(?string $userEmail): self
    {
        $this->userEmail = $userEmail;

        return $this;
    }

    public function getMethod(): ?string
    {
        return $this->method;
    }

    public function setMethod(string $method): self
    {
        $this->method = $method;

        return $this;
    }

    public function getFichier(): ?string
    {
        return $this->fichier;
    }

    public function setFichier(?string $fichier): self
    {
        $this->fichier = $fichier;

        return $this;
    }

    public function getParametres(): ?array
    {
        return $this->parametres;
    }

    public function setParametres(?array $parametres): self
    {
        $this->parametres = $parametres;

        return $this;
    }

    public function getParametresCaches(): ?array
    {
        return $this->parametresCaches;
    }

    public function setParametresCaches(?array $parametresCaches): self
    {
        $this->parametresCaches = $parametresCaches;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?TachesStatus $status): self
    {
        $this->status = (string)$status->value;

        return $this;
    }

    public function getResult(): ?array
    {
        return $this->result;
    }

    public function setResult(?array $result): self
    {
        $this->result = $result;

        return $this;
    }

    public function getProgress(): ?array
    {
        return $this->progress;
    }

    public function setProgress(?array $progress): self
    {
        $this->progress = $progress;

        return $this;
    }

    public function getStartdate(): ?\DateTimeInterface
    {
        return $this->startdate;
    }

    public function setStartdate(?\DateTimeInterface $startdate): self
    {
        $this->startdate = $startdate;

        return $this;
    }

    public function getEnddate(): ?\DateTimeInterface
    {
        return $this->enddate;
    }

    public function setEnddate(?\DateTimeInterface $enddate): self
    {
        $this->enddate = $enddate;

        return $this;
    }

    public function getCreationdate(): ?\DateTimeInterface
    {
        return $this->creationdate;
    }

    public function setCreationdate(\DateTimeInterface $creationdate): self
    {
        $this->creationdate = $creationdate;

        return $this;
    }

    public function getPid(): ?int
    {
        return $this->pid;
    }

    public function setPid(?int $pid): self
    {
        $this->pid = $pid;

        return $this;
    }

    public function getSignature(): ?string
    {
        return $this->signature;
    }

    public function setSignature(?string $signature): self
    {
        $this->signature = $signature;
        return $this;
    }

    public function getTacheSuivante(): ?Tache
    {
        return $this->tacheSuivante;
    }

    public function setTacheSuivante(?Tache $tacheSuivante): self
    {
        $this->tacheSuivante = $tacheSuivante;

        return $this;
    }

    /**
     * @todo peut-être passer plutôt ça sur les services pour récupérer les paramètres ?
     *
     * @return string
     */
    public function getTachePath(): string
    {
        if ($this->getId() == null) {
            throw new \Exception('La tâche n\'est pas encore créée (id null)');
        }
        return false ;
        //return $this->kernel->getProjectDir() . $this->getParameter('app.task_folder') . $this->getId() . '/';
    }

    public function get()
    {
        $ret = [
            'id' => $this->getId(),
            'userEmail' => $this->getUserEmail(),
            'method' => $this->getMethod(),
            'fichier' => $this->getFichier(),
            'parametres' => $this->getParametres(),
            'parametresCaches' => $this->getParametresCaches(),
            'status' => $this->getStatus(),
            'result' => $this->getResult(),
            'logs' => $this->getLogs(),
            'progress' => $this->getProgress(),
            'startdate' => $this->getStartdate(),
            'enddate' => $this->getEnddate(),
            'creationDate' => $this->getCreationdate(),
            'pid' => $this->getPid(),
            'signature' => $this->getSignature()
        ];
        return $ret;
    }

    public function getLogs()
    {
        $logs = ['error' => [], 'warning' => [], 'info' => []];
        $results = $this->getResult();
        foreach ($results as $result) {
            if (is_array($result)) {
                $type = array_shift($result);
                $logs[$type][] = $result;
            }
        }
        return $logs;
    }

    public function log(string $type, string $message): void
    {
        if (! $this->result) {
            $this->result = [] ;
        }
        $this->result[] = [$type, $message] ;
    }
}
