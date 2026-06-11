<?php

namespace ApidaeTourisme\ApidaeBundle;

use Symfony\Component\Security\Core\User\UserInterface;

class ApidaeUser implements UserInterface
{
    private string $email;
    private string $gravatar ;
    private string $firstname ;
    private string $lastname ;
    private string $type ;
    private string $profession ;
    private array $roles = [];

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getGravatar(): ?string
    {
        return $this->gravatar ;
    }

    public function setGravatar(string $gravatar)
    {
        $this->gravatar = $gravatar ;
    }

    public function getFirstname(): ?string
    {
        return $this->firstname ;
    }

    public function setFirstname(string $firstname)
    {
        $this->firstname = $firstname ;
    }

    public function getLastname(): ?string
    {
        return $this->lastname ;
    }

    public function setLastname(string $lastname)
    {
        $this->lastname = $lastname ;
    }

    public function getType(): ?string
    {
        return $this->type ;
    }

    public function setType(string $type)
    {
        $this->type = $type ;
    }

    public function getProfession(): ?string
    {
        return $this->profession ;
    }

    public function setProfession(string $profession)
    {
        $this->profession = $profession ;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        if ($this->email === '') {
            throw new \LogicException('Email utilisateur non défini.');
        }

        return $this->email;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    public function setRoles(array $roles): self
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
        // $this->plainPassword = null;
    }
}
