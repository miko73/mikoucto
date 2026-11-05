<?php

namespace App\Modules\Core\Infrastructure\Identity;

interface SigningKeys
{
    public function get(): array;
}
