<?php

namespace App\Modules\Core\Api;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route('/api/v1/health', name: 'api_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok', 'service' => 'mikoucto-api']);
    }

    #[Route('/api/v1/health/database', name: 'api_health_database', methods: ['GET'])]
    public function database(Connection $connection): JsonResponse
    {
        try {
            if ((int) $connection->fetchOne('SELECT 1') !== 1) {
                throw new \RuntimeException('Unexpected database response.');
            }
        } catch (\Throwable) {
            // Nevracet DSN, heslo ani vnitrni zpravu databazoveho ovladace.
            return new JsonResponse([
                'type' => 'about:blank',
                'title' => 'Database unavailable',
                'status' => 503,
                'detail' => 'Databaze neni dostupna. Overte SSH tunel a konfiguraci pripojeni.',
            ], 503, ['Content-Type' => 'application/problem+json', 'Cache-Control' => 'no-store']);
        }

        return new JsonResponse(['status' => 'ok', 'database' => 'ok'], 200, ['Cache-Control' => 'no-store']);
    }
}
