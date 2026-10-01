<?php

namespace ApidaeTourisme\ApidaeBundle ;

use Exception;
use PierreGranger\ApidaeMembres;
use PierreGranger\ApidaeException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use HWI\Bundle\OAuthBundle\OAuth\Response\UserResponseInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use HWI\Bundle\OAuthBundle\Security\Core\User\OAuthAwareUserProviderInterface;

final class ApidaeUserProvider implements UserProviderInterface, OAuthAwareUserProviderInterface
{
    public function __construct(private ApidaeMembres $apidaeMembres)
    {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        try {
            $remoteApidaeUser = $this->apidaeMembres->getUserByMail($identifier) ;
        } catch (Exception $e) {
            throw new UserNotFoundException('User not found on Apidae') ;
        }
        if (! $remoteApidaeUser) {
            throw new UserNotFoundException('User not found on Apidae') ;
        }

        $localApidaeUser = new ApidaeUser() ;
        $localApidaeUser->setEmail($remoteApidaeUser['eMail']) ;
        $localApidaeUser->setApidaeId((int) $remoteApidaeUser['id']) ;
        $localApidaeUser->setFirstname($remoteApidaeUser['prenom']) ;
        $localApidaeUser->setLastname($remoteApidaeUser['nom']) ;
        $localApidaeUser->setType($remoteApidaeUser['type']) ;
        if (isset($remoteApidaeUser['profession'])) {
            $localApidaeUser->setProfession($remoteApidaeUser['profession']) ;
        }
        $localApidaeUser->setGravatar('https://www.gravatar.com/avatar/'.md5($remoteApidaeUser['eMail'])) ;
        $localApidaeUser->setRoles($this->getRolesFromUserApidae($remoteApidaeUser)) ;

        return $localApidaeUser ;
    }

    public function loadUserByOAuthUserResponse(UserResponseInterface $response): UserInterface
    {
        $user = $this->loadUserByIdentifier($response->getNickname());
        $user->setApidaeToken($response->getAccessToken());

        return $user;
    }

    public function loadUserByUsername($username): UserInterface
    {
        return $this->loadUserByIdentifier($username);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        $refreshed = $this->loadUserByIdentifier($user->getUserIdentifier());
        if ($user instanceof ApidaeUser) {
            // Le token SSO n'est connu qu'au login : on le conserve d'un refresh à l'autre
            $refreshed->setApidaeToken($user->getApidaeToken());
        }

        return $refreshed;
    }

    public function supportsClass($class): bool
    {
        return 'ApidaeTourisme\\ApidaeBundle\\ApidaeUser' === $class;
    }

    private function getRolesFromUserApidae(array $userApidae)
    {
        $membreApidae = null;
        try {
            $membreApidae = $this->apidaeMembres->getMembreById($userApidae['membre']['id']);
        } catch (ApidaeException $e) {
            throw new \Exception('Impossible de récupérer les informations du membre ' . $userApidae['membre']['id'] . ' sur Apidae : ' . $e->getMessage() . ' ' . json_encode($e->getDetails()));
        } catch (\Exception $e) {
            throw new \Exception('Impossible de récupérer les informations du membre ' . $userApidae['membre']['id'] . ' sur Apidae : ' . $e->getMessage());
        }
        if (!$membreApidae) {
            throw new \Exception('Impossible de récupérer les informations du membre ' . $userApidae['membre']['id'] . ' de l\'utilisateur sur Apidae');
        }

        $roles = is_array($userApidae['permissions']) ? $userApidae['permissions'] : [];

        array_walk($roles, function (&$value, $key) {
            $value = 'ROLE_SIT_' . $value;
        });

        $roles[] = 'ROLE_SIT_MEMBRE_' . $userApidae['membre']['id'];
        if ($userApidae['membre']['id'] == 1157) {
            $roles[] = 'ROLE_SIT_APIDAE_FEDERAL';
            if (in_array($userApidae['id'], array(14015))) {
                $roles[] = 'ROLE_SIT_APIDAE_DEV';
            }
        }

        if (isset($membreApidae['permissions'])) {
            foreach ($membreApidae['permissions'] as $p) {
                $roles[] = 'ROLE_SIT_MEMBRE_' . $p;
            }
        }

        if (isset($membreApidae['type'])) {
            $roles[] = 'ROLE_MEMBRE_TYPE_' . $membreApidae['type']['id'];
            if (in_array($membreApidae['type']['id'], [
                3799, // Editeur de Services (Hotentic)
                3793, // Prestataire Technique (Clever)
                3795, // AMO / formateurs / conseil (Sipea)
            ])) {
                $roles[] = 'ROLE_FOURNISSEUR_SERVICE';
            } // Ancien rôle pour ZD et Analyse mais trop peu fiable

            if (in_array($userApidae['membre']['id'], [
                601, // Editeur de Services (Hotentic)
                646, // Prestataire Technique (Clever)
                419, // AMO / formateurs / conseil (Sipea)
            ])) {
                $roles[] = 'ROLE_PARTENAIRE_TECHNIQUE';
            } // Accès à Zendesk et aux outils d'analyse par la console

            if ($membreApidae['type']['id'] == 5624) {
                $roles[] = 'ROLE_LEADER';
            }
        }

        // Identifiant des leaders (en attendant d'avoir le type membre Contributeur Leader)
        if (isset($membreApidae['type'])) {
            if (in_array($membreApidae['id'], array(
                1, // ARAT
                2, // Rhône
                5, // Ain
                6, // Isère
                11, // Drôme
                96, // Ardèche
                171, // SMB
                212, // Loire
                590, // PACA
                862, // Tarn
                1019, // CRT Paris
                1080, // PDD
                1147, // Allier Tourisme
                1158, // HL
                1182, // Cantal
                1803, // Charentes
                1869, // Nouvelle Calédonie
                2101, // Tarn & Garonne
            ))) {
                $roles[] = 'ROLE_LEADER';
            }
        }

        return $roles ;
    }
}
