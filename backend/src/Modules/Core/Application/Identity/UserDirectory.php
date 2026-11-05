<?php

namespace App\Modules\Core\Application\Identity;

interface UserDirectory
{
    public function findActive(EntraIdentity $identity): ?ApplicationUser;
}
