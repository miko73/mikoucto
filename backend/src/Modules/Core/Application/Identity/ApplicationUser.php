<?php

namespace App\Modules\Core\Application\Identity;

use Symfony\Component\Security\Core\User\UserInterface;

final readonly class ApplicationUser implements UserInterface
{
    public function __construct(public string $id, public string $displayName)
    {
    }

    public function getUserIdentifier(): string { return $this->id; }
    public function getRoles(): array { return ['ROLE_USER']; }
    public function eraseCredentials(): void { }
}
