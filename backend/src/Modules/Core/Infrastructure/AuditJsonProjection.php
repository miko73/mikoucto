<?php
namespace App\Modules\Core\Infrastructure;

/** Preserve exact financial values in every HTTP presentation of the audit. */
final class AuditJsonProjection
{
    public static function fields(): string
    {
        return implode(',',array_map(static fn(string $column): string=>
            "CASE WHEN table_name='ucto.document_lines' THEN $column || jsonb_build_object('md',round(($column->>'md')::numeric,2)::text,'dal',round(($column->>'dal')::numeric,2)::text) ELSE $column END AS $column",
            ['old_data','new_data']));
    }
}
