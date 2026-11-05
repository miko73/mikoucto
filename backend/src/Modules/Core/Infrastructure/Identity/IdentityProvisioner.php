<?php

namespace App\Modules\Core\Infrastructure\Identity;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final class IdentityProvisioner
{
    public static function provision(Connection $connection, array $input): array
    {
        foreach (['tenant', 'object'] as $key) {
            if (!EntraSettings::isGuid($input[$key] ?? null)) { throw new IdentityProvisioningException('Tenant and object must be GUIDs.'); }
        }
        foreach (['display_name', 'unit_name'] as $key) {
            if (!is_string($input[$key] ?? null) || mb_strlen(trim($input[$key])) < 1 || mb_strlen($input[$key]) > 200) {
                throw new IdentityProvisioningException('Names must contain 1 to 200 characters.');
            }
        }
        if (!is_string($input['unit_code'] ?? null) || preg_match('/^[A-Z0-9_-]{1,32}$/D', $input['unit_code']) !== 1
            || !in_array($input['role'] ?? '', ['administrator', 'accountant', 'auditor', 'reader'], true)) {
            throw new IdentityProvisioningException('Invalid unit code or application role.');
        }
        if ($connection->fetchOne('SELECT current_user') !== 'mikoucto_owner') {
            throw new IdentityProvisioningException('Provisioning requires the migration owner role.');
        }
        return $connection->transactional(static function (Connection $connection) use ($input): array {
            $id = $connection->fetchOne('SELECT id FROM core.users WHERE entra_tenant_id=?::uuid AND entra_object_id=?::uuid', [$input['tenant'], $input['object']]) ?: Uuid::v7()->toRfc4122();
            $request = Uuid::v7()->toRfc4122();
            $connection->fetchOne('SELECT core.set_context(?::uuid, ?, ?)', [$id, $request, 'setup']);
            $connection->executeStatement('INSERT INTO core.users(id, entra_tenant_id, entra_object_id, display_name) VALUES (?, ?, ?, ?) ON CONFLICT (entra_tenant_id, entra_object_id) DO NOTHING', [$id, $input['tenant'], $input['object'], trim($input['display_name'])]);
            $user = $connection->fetchAssociative('SELECT id, active FROM core.users WHERE entra_tenant_id=?::uuid AND entra_object_id=?::uuid', [$input['tenant'], $input['object']]);
            if (!$user['active']) { throw new IdentityProvisioningException('The existing user is inactive; provisioning will not reactivate it.'); }
            $connection->fetchOne('SELECT core.set_context(?::uuid, ?, ?)', [$user['id'], $request, 'setup']);
            $connection->executeStatement('INSERT INTO core.accounting_units(id, code, name) VALUES (?, ?, ?) ON CONFLICT (code) DO NOTHING', [Uuid::v7()->toRfc4122(), $input['unit_code'], trim($input['unit_name'])]);
            $unit = $connection->fetchAssociative('SELECT id, active FROM core.accounting_units WHERE code=? FOR UPDATE', [$input['unit_code']]);
            if (!$unit['active']) { throw new IdentityProvisioningException('The existing unit is inactive; provisioning will not reactivate it.'); }
            $connection->fetchOne("SELECT set_config('app.accounting_unit_id', ?, true)", [$unit['id']]);
            $connection->executeStatement('INSERT INTO core.user_roles(id, user_id, accounting_unit_id, role_code) VALUES (?, ?, ?, ?) ON CONFLICT (user_id, accounting_unit_id, role_code) DO NOTHING', [Uuid::v7()->toRfc4122(), $user['id'], $unit['id'], $input['role']]);
            return ['user_id' => $user['id'], 'accounting_unit_id' => $unit['id'], 'unit_code' => $input['unit_code'], 'role' => $input['role']];
        });
    }
}
