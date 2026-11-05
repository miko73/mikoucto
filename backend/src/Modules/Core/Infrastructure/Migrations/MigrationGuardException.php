<?php

namespace App\Modules\Core\Infrastructure\Migrations;

// Jen kontrolovane zpravy bez DSN, hesel nebo chyb databazoveho ovladace.
final class MigrationGuardException extends \RuntimeException
{
}
