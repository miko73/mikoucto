<?php

namespace App\Modules\Core\Application\Identity;

interface AccessTokenValidator
{
    public function validate(string $token): EntraIdentity;
}
