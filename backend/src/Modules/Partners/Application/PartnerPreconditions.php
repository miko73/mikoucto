<?php

namespace App\Modules\Partners\Application;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

final class PartnerPreconditions
{
    public static function version(?string $etag, string $id): int
    {
        if ($etag === null || $etag === '') { throw new HttpException(428,'If-Match is required.'); }
        if (preg_match('/^"([0-9a-fA-F-]{36}):([1-9][0-9]{0,9})"$/D',$etag,$parts) !== 1 || (int)$parts[2] > 2147483647) {
            throw new BadRequestHttpException('Use the strong ETag returned by this resource.');
        }
        if (strtolower($parts[1]) !== strtolower($id)) { throw new PreconditionFailedHttpException(); }
        return (int)$parts[2];
    }

    public static function idempotencyKey(?string $key): string
    {
        if ($key === null || preg_match('/^[a-zA-Z0-9._:-]{1,128}$/D',$key) !== 1) { throw new BadRequestHttpException('Idempotency-Key is required.'); }
        return $key;
    }
}
