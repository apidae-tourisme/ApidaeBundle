<?php

namespace ApidaeTourisme\ApidaeBundle\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use ApidaeTourisme\ApidaeBundle\Entity\Tache;
use ApidaeTourisme\ApidaeBundle\Config\TachesStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @method Tache|null find($id, $lockMode = null, $lockVersion = null)
 * @method Tache|null findOneBy(array $criteria, array $orderBy = null)
 * @method Tache[]    findAll()
 * @method Tache[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TacheRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tache::class);
    }

    public function getTachesByUtilisateuId(int $id): array|null
    {
        $ret = $this->createQueryBuilder('t')
            ->andWhere('t.userEmail = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getResult();
        return $this->refreshRet($ret) ;
    }

    public function getTacheById(int $id): Tache|null
    {
        $ret = $this->createQueryBuilder('t')
            ->andWhere('t.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
        return $this->refreshRet($ret) ;
    }

    public function getTacheToRun(): Tache|null
    {
        $ret = $this->createQueryBuilder('t')
            ->andWhere('t.status = :status')
            ->setParameter('status', TachesStatus::TO_RUN->value)
            ->orderBy('t.creationdate', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        return $this->refreshRet($ret) ;
    }

    /**
     * Réserve atomiquement la prochaine tâche TO_RUN (TO_RUN → RUNNING).
     * Utilise FOR UPDATE SKIP LOCKED pour les workers concurrents.
     */
    public function claimNextTache(): ?Tache
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = <<<'SQL'
            WITH next AS (
                SELECT id FROM tache
                WHERE status = :to_run
                ORDER BY creationdate ASC
                LIMIT 1
                FOR UPDATE SKIP LOCKED
            )
            UPDATE tache t
            SET status = :running,
                startdate = NOW(),
                enddate = NULL,
                progress = NULL,
                result = :empty_result,
                pid = NULL
            FROM next
            WHERE t.id = next.id
            RETURNING t.id
            SQL;

        $id = $conn->fetchOne($sql, [
            'to_run' => TachesStatus::TO_RUN->value,
            'running' => TachesStatus::RUNNING->value,
            'empty_result' => '[]',
        ]);

        if ($id === false) {
            return null;
        }

        return $this->getTacheById((int) $id);
    }

    public function getTachesToRun(): array|null
    {
        return $this->getTachesByStatus(TachesStatus::TO_RUN);
    }

    public function getTachesByStatus(TachesStatus $status): array|null
    {
        $ret = $this->createQueryBuilder('t')
            ->andWhere('t.status = :status')
            ->setParameter('status', $status->value)
            ->orderBy('t.creationdate', 'ASC')
            ->getQuery()
            ->getResult();
        return $this->refreshRet($ret) ;
    }

    public function getTachesNumberByStatus(string $status): int
    {
        $ret = $this->createQueryBuilder('t')
            ->select('count(t.id)')
            ->andWhere('t.status = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $ret;
    }

    public function getTacheBySignature(string $signature): Tache|null
    {
        return $this->findOneBy(['signature' => $signature], ['creationdate' =>'ASC']) ;
    }

    public function getTachesBySignature(string $signature): array
    {
        return $this->findBy(['signature' => $signature], ['creationdate' =>'ASC']) ;
    }

    /**
     * @param array<string> Liste de signatures
     * @return array<int> Identifiants
     */
    private function getTachesIdBySignatureFromView(array $valeurs): array
    {
        /**
         * @see https://symfony.com/doc/current/doctrine.html#querying-with-sql
         */
        $conn = $this->getEntityManager()->getConnection() ;
        $sql = ' select id from tache_by_signature where signature in (:signatures) ' ;
        return $conn->executeQuery($sql, ['signatures' => $valeurs], ['signatures' => ArrayParameterType::STRING])->fetchFirstColumn() ;
    }

    public function findBySignatures(array $valeurs): array|null
    {
        $ids = $this->getTachesIdBySignatureFromView($valeurs) ;

        $qb = $this->createQueryBuilder('t')
            ->orderBy('t.creationdate', 'DESC')
            ->setParameter('ids', $ids) ;

        $qb->andWhere('t.id in (:ids)') ;

        $query = $qb->getQuery() ;
        $results = $query->getResult();

        return $results ;
    }

    public function findBySignature(string $signature): Tache|null
    {
        $ids = $this->getTachesIdBySignatureFromView([$signature]) ;
        if (sizeof($ids) == 1) {
            return $this->findOneBy(['id' => $ids[0]]) ;
        }
        return null ;
    }

    public function findLast(): Tache|null
    {
        $ret = $this->createQueryBuilder('t')
            ->orderBy('t.creationdate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        return $this->refreshRet($ret) ;
    }

    private function refreshRet($ret)
    {
        if ($ret == null) {
            return $ret ;
        }
        if (is_array($ret)) {
            foreach ($ret as $r) {
                $this->getEntityManager()->refresh($r) ;
            }
        } else {
            $this->getEntityManager()->refresh($ret) ;
        }
        return $ret ;
    }
}
