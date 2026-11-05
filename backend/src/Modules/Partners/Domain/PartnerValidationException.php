<?php

namespace App\Modules\Partners\Domain;

use App\Modules\Core\Application\InputValidationException;

final class PartnerValidationException extends InputValidationException
{
    public function __construct(array $violations) { parent::__construct($violations,'Invalid partner input'); }
}
