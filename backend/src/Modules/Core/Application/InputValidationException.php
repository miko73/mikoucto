<?php

namespace App\Modules\Core\Application;

use Symfony\Component\HttpKernel\Exception\HttpException;

class InputValidationException extends HttpException
{
    public function __construct(public readonly array $violations,public readonly string $title='Invalid input',int $statusCode=422) { parent::__construct($statusCode,$title); }
}
