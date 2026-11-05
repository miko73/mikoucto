<?php

namespace App\Modules\Core\Api;

use App\Modules\Core\Application\Identity\ApplicationUser;
use App\Modules\Core\Application\Identity\IdentitySession;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

final class IdentityController extends AbstractController
{
    private const GUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(private IdentitySession $session) { }

    #[Route('/api/v1/me', methods: ['GET'])]
    public function me(#[CurrentUser] ApplicationUser $user): JsonResponse
    {
        return new JsonResponse(['id' => $user->id, 'display_name' => $user->displayName]);
    }

    #[Route('/api/v1/accounting-units', methods: ['GET'])]
    public function units(#[CurrentUser] ApplicationUser $user): JsonResponse
    {
        return new JsonResponse(['items' => $this->session->run($user, null,
            static fn (Connection $connection): array => $connection->fetchAllAssociative('SELECT id, code, name, version FROM core.accounting_units ORDER BY code'))]);
    }

    #[Route('/api/v1/accounting-units/{unit}/permissions', requirements: ['unit' => self::GUID], methods: ['GET'])]
    public function permissions(string $unit, #[CurrentUser] ApplicationUser $user): JsonResponse
    {
        $this->denyAccessUnlessGranted('core.units.read', $unit);
        return new JsonResponse(['items' => $this->session->run($user, $unit,
            static fn (Connection $connection): array => $connection->fetchFirstColumn('SELECT DISTINCT rp.permission_code FROM core.user_roles ur JOIN core.role_permissions rp ON rp.role_code=ur.role_code WHERE ur.user_id=?::uuid AND ur.accounting_unit_id=?::uuid ORDER BY rp.permission_code', [$user->id, $unit]))]);
    }

    #[Route('/api/v1/accounting-units/{unit}/users', requirements: ['unit' => self::GUID], methods: ['GET'])]
    public function users(string $unit, #[CurrentUser] ApplicationUser $user): JsonResponse
    {
        $this->denyAccessUnlessGranted('core.users.read', $unit);
        return new JsonResponse(['items' => $this->session->run($user, $unit,
            static fn (Connection $connection): array => self::decodeFields($connection->fetchAllAssociative('SELECT u.id, u.display_name, u.active, jsonb_agg(ur.role_code ORDER BY ur.role_code)::text AS roles FROM core.users u JOIN core.user_roles ur ON ur.user_id=u.id WHERE ur.accounting_unit_id=?::uuid GROUP BY u.id ORDER BY u.display_name LIMIT 200', [$unit]), ['roles']))]);
    }

    #[Route('/api/v1/accounting-units/{unit}/roles', requirements: ['unit' => self::GUID], methods: ['GET'])]
    public function roles(string $unit, #[CurrentUser] ApplicationUser $user): JsonResponse
    {
        $this->denyAccessUnlessGranted('core.roles.read', $unit);
        return new JsonResponse(['items' => $this->session->run($user, $unit,
            static fn (Connection $connection): array => $connection->fetchAllAssociative('SELECT code, name FROM core.roles ORDER BY code'))]);
    }

    #[Route('/api/v1/accounting-units/{unit}/users/{target}/roles', requirements: ['unit' => self::GUID, 'target' => self::GUID], methods: ['POST'])]
    public function assignRole(string $unit, string $target, Request $request, #[CurrentUser] ApplicationUser $user): JsonResponse
    {
        $this->denyAccessUnlessGranted('core.roles.manage', $unit);
        $body = $request->toArray();
        $role = $body['role'] ?? null;
        if (!is_string($role) || !in_array($role, ['administrator', 'accountant', 'auditor', 'reader'], true) || array_keys($body) !== ['role']) {
            throw new BadRequestHttpException();
        }
        $id = $this->session->run($user, $unit, static fn (Connection $connection): string => $connection->fetchOne(
            'SELECT core.assign_role(?::uuid, ?::uuid, ?, ?::uuid)', [$target, $unit, $role, Uuid::v7()->toRfc4122()]), $request->attributes->get('_request_id'));
        return new JsonResponse(['id' => $id, 'user_id' => $target, 'accounting_unit_id' => $unit, 'role' => $role]);
    }

    #[Route('/api/v1/accounting-units/{unit}/users/{target}/roles/{role}', requirements: ['unit' => self::GUID, 'target' => self::GUID, 'role' => 'administrator|accountant|auditor|reader'], methods: ['DELETE'])]
    public function revokeRole(string $unit, string $target, string $role, Request $request, #[CurrentUser] ApplicationUser $user): JsonResponse
    {
        $this->denyAccessUnlessGranted('core.roles.manage', $unit);
        $this->session->run($user, $unit, static fn (Connection $connection) => $connection->fetchOne(
            'SELECT core.revoke_role(?::uuid, ?::uuid, ?)', [$target, $unit, $role]), $request->attributes->get('_request_id'));
        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/accounting-units/{unit}/audit', requirements: ['unit' => self::GUID], methods: ['GET'])]
    public function audit(string $unit, #[CurrentUser] ApplicationUser $user): JsonResponse
    {
        $this->denyAccessUnlessGranted('core.audit.read', $unit);
        return new JsonResponse(['items' => $this->session->run($user, $unit,
            static fn (Connection $connection): array => self::decodeFields($connection->fetchAllAssociative('SELECT id, occurred_at, request_id, user_id, client, table_name, row_id, operation, '.\App\Modules\Core\Infrastructure\AuditJsonProjection::fields().', to_jsonb(changed_cols)::text AS changed_cols FROM core.audit_log ORDER BY occurred_at DESC, id DESC LIMIT 100'), ['old_data', 'new_data', 'changed_cols']))]);
    }

    private static function decodeFields(array $rows, array $fields): array
    {
        foreach ($rows as &$row) {
            foreach ($fields as $field) {
                if ($row[$field] !== null) { $row[$field] = json_decode($row[$field], true, 512, JSON_THROW_ON_ERROR); }
            }
        }
        return $rows;
    }
}
