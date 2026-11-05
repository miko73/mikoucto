<?php

namespace App\Modules\Partners\State;

use App\Modules\Core\Application\Identity\ApplicationUser;
use App\Modules\Core\Infrastructure\Identity\EntraSettings;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class PartnerAccess
{
    public function __construct(private Security $security,private AuthorizationCheckerInterface $authorization,private RequestStack $requests) { }

    public function context(array $variables,string $permission): array
    {
        $unit = $variables['unit'] ?? null; $id = $variables['id'] ?? null;
        if (!EntraSettings::isGuid($unit) || ($id !== null && !EntraSettings::isGuid($id))) { throw new BadRequestHttpException(); }
        // Stejna UUID musi mit stejny transakcni zamek i pri jine velikosti pismen v URL.
        $unit=strtolower($unit); $id=$id===null?null:strtolower($id);
        $user = $this->security->getUser();
        if (!$user instanceof ApplicationUser || !$this->authorization->isGranted($permission,$unit)) { throw new AccessDeniedException(); }
        return [$user,$unit,$id,$this->requests->getCurrentRequest() ?? throw new \LogicException('Missing HTTP request.')];
    }

    public function pagination(Request $request,bool $filters): array
    {
        $query=$request->query->all();
        if (array_diff(array_keys($query),$filters?['page','itemsPerPage','search','active']:['page','itemsPerPage']) !== []) { throw new BadRequestHttpException(); }
        foreach ($query as $value) { if (!is_scalar($value)) { throw new BadRequestHttpException(); } }
        $page=(string)($query['page']??'1'); $size=(string)($query['itemsPerPage']??'20');
        if (!ctype_digit($page) || !ctype_digit($size) || (int)$page<1 || (int)$page>100000 || (int)$size<1 || (int)$size>100) { throw new BadRequestHttpException(); }
        $search=trim((string)($query['search']??''));
        if (mb_strlen($search)>100 || (isset($query['active']) && !in_array($query['active'],['true','false'],true))) { throw new BadRequestHttpException(); }
        return [(int)$page,(int)$size,$search,isset($query['active'])?$query['active']==='true':null];
    }
}
