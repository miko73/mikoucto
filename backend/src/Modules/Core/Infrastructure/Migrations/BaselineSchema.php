<?php

namespace App\Modules\Core\Infrastructure\Migrations;

use Doctrine\DBAL\Connection;

final class BaselineSchema
{
    public static function assertCompatible(Connection $connection): void
    {
        $tables = [
            'core.audit_log' => [
                'id' => 'bigint', 'occurred_at' => 'timestamp with time zone', 'tx_id' => 'bigint',
                'request_id' => 'text', 'user_id' => 'uuid', 'client' => 'text', 'table_name' => 'text',
                'row_id' => 'text', 'operation' => 'character(1)', 'old_data' => 'jsonb', 'new_data' => 'jsonb',
                'changed_cols' => 'text[]', 'sealed_seq' => 'bigint', 'prev_hash' => 'bytea', 'row_hash' => 'bytea',
            ],
            'core.audit_seal_state' => [
                'id' => 'integer', 'last_seq' => 'bigint', 'last_hash' => 'bytea', 'updated_at' => 'timestamp with time zone',
            ],
        ];
        foreach ($tables as $table => $expected) {
            $actual = $connection->fetchAllKeyValue(
                'SELECT attname, format_type(atttypid, atttypmod) FROM pg_attribute WHERE attrelid = to_regclass(?) AND attnum > 0 AND NOT attisdropped ORDER BY attnum',
                [$table],
            );
            if ($actual !== $expected) {
                throw new MigrationGuardException('Incompatible baseline columns: '.$table);
            }
            if ($connection->fetchOne('SELECT pg_get_userbyid(relowner) FROM pg_class WHERE oid=to_regclass(?)', [$table]) !== 'mikoucto_owner') {
                throw new MigrationGuardException('Unexpected baseline owner: '.$table);
            }
        }
        if ($connection->fetchOne("SELECT relkind FROM pg_class WHERE oid=to_regclass('core.audit_log')") !== 'p') {
            throw new MigrationGuardException('Audit table is not partitioned.');
        }
        foreach (['core', 'ext'] as $schema) {
            if ($connection->fetchOne('SELECT pg_get_userbyid(nspowner) FROM pg_namespace WHERE nspname=?', [$schema]) !== 'mikoucto_owner') {
                throw new MigrationGuardException('Unexpected schema owner: '.$schema);
            }
        }
        if (!$connection->fetchOne("SELECT EXISTS(SELECT 1 FROM pg_extension e JOIN pg_namespace n ON n.oid=e.extnamespace WHERE e.extname='pgcrypto' AND n.nspname='ext')")) {
            throw new MigrationGuardException('Missing pgcrypto extension in ext schema.');
        }
        foreach ([
            'core.set_context(uuid,text,text)', 'core.audit_trigger()', 'core.audit_block_truncate()',
            'core.audit_attach(regclass,text)', 'core.audit_log_guard()', 'core.audit_ensure_partitions(integer)',
            'core.audit_row_digest(bytea,core.audit_log)', 'core.audit_seal(integer)', 'core.audit_verify()',
        ] as $signature) {
            if ($connection->fetchOne('SELECT to_regprocedure(?)', [$signature]) === null) {
                throw new MigrationGuardException('Missing baseline function: '.$signature);
            }
            if ($connection->fetchOne('SELECT pg_get_userbyid(proowner) FROM pg_proc WHERE oid=to_regprocedure(?)', [$signature]) !== 'mikoucto_owner') {
                throw new MigrationGuardException('Unexpected function owner: '.$signature);
            }
        }
        $guards = $connection->fetchFirstColumn("SELECT tgname FROM pg_trigger WHERE tgrelid='core.audit_log'::regclass AND NOT tgisinternal ORDER BY tgname");
        if ($guards !== ['audit_log_guard', 'audit_log_truncate']) {
            throw new MigrationGuardException('Missing audit protection triggers.');
        }
        if (!$connection->fetchOne("SELECT to_regclass('core.audit_log_default') IS NOT NULL AND to_regclass('core.audit_log_' || to_char(now() AT TIME ZONE 'UTC', 'YYYYMM')) IS NOT NULL")) {
            throw new MigrationGuardException('Missing audit partitions.');
        }
        foreach (['mikoucto_app', 'mikoucto_read'] as $role) {
            if (!$connection->fetchOne("SELECT has_table_privilege(?, 'core.audit_log', 'SELECT')", [$role])) {
                throw new MigrationGuardException('Missing audit read permission: '.$role);
            }
            foreach (['INSERT', 'UPDATE', 'DELETE'] as $permission) {
                if ($connection->fetchOne("SELECT has_table_privilege(?, 'core.audit_log', ?)", [$role, $permission])) {
                    throw new MigrationGuardException('Unexpected direct audit write permission: '.$role);
                }
            }
        }
        if (!$connection->fetchOne('SELECT ok FROM core.audit_verify()')) {
            throw new MigrationGuardException('Audit chain verification failed.');
        }
    }
}
