<?php

namespace App\Modules\Core\Infrastructure\Identity;

use Doctrine\DBAL\Connection;

final class ApplicationRoleGuard
{
    private bool $checked = false;

    public function __construct(private Connection $connection) { }

    public function check(): void
    {
        if ($this->checked) { return; }
        $role = $this->connection->fetchAssociative("SELECT current_user AS current_role, session_user AS login_role,
            rolsuper OR rolbypassrls OR rolcreaterole OR rolcreatedb AS privileged,
            pg_has_role(current_user, 'mikoucto_owner', 'MEMBER') AS owner_member
            FROM pg_roles WHERE rolname=current_user");
        if ($role === false || $role['current_role'] !== 'mikoucto_app' || $role['login_role'] !== 'mikoucto_app'
            || $role['privileged'] || $role['owner_member']) {
            throw new IdentityConfigurationException('Protected API requires the unprivileged application database role.');
        }
        $this->checked = true;
    }
}
