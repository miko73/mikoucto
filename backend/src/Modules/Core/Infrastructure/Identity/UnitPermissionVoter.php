<?php

namespace App\Modules\Core\Infrastructure\Identity;

use App\Modules\Core\Application\Identity\ApplicationUser;
use App\Modules\Core\Application\Identity\IdentitySession;
use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class UnitPermissionVoter extends Voter
{
    public function __construct(private IdentitySession $session) { }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return str_starts_with($attribute, 'core.') && EntraSettings::isGuid($subject);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        return $user instanceof ApplicationUser && $this->session->run($user, $subject,
            static fn (Connection $connection): bool => (bool) $connection->fetchOne('SELECT core.has_permission(?::uuid, ?)', [$subject, $attribute]));
    }
}
