<?php
namespace App\Modules\Accounting\State;

use App\Modules\Partners\State\PartnerAccess;

final class ReportAccess
{
    public function __construct(private PartnerAccess $access) { }
    public function context(array $variables): array
    {
        $context=$this->access->context($variables,'core.ucto.documents.read');
        foreach (['core.units.read','core.ucto.accounts.read','core.ucto.periods.read'] as $permission) {
            $this->access->context($variables,$permission);
        }
        return $context;
    }
}
