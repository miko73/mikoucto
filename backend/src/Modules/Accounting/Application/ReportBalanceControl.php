<?php

namespace App\Modules\Accounting\Application;

/** Independent check of the six SQL aggregates; no rounding or auto-balancing. */
final class ReportBalanceControl
{
    public static function apply(array $totals): array
    {
        $balanced=true;
        foreach (['opening','turnover','closing'] as $group) {
            $difference=DecimalAmount::difference($totals[$group.'Md'],$totals[$group.'Dal']);
            $totals[$group.'Difference']=$difference;
            $balanced=$balanced&&$difference==='0.00';
        }
        $totals['balanced']=$balanced;
        return $totals;
    }
}
