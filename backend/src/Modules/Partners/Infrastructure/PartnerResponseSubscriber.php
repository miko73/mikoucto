<?php

namespace App\Modules\Partners\Infrastructure;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

final class PartnerResponseSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array { return ['kernel.response'=>['onResponse',-128]]; }
    public function onResponse(ResponseEvent $event): void
    {
        $request=$event->getRequest(); $response=$event->getResponse();
        if (!$response->isSuccessful()) { return; }
        foreach (['_partner_etag'=>'ETag','_partner_location'=>'Location','_partner_total'=>'X-Total-Count'] as $key=>$header) {
            if ($request->attributes->has($key)) { $response->headers->set($header,(string)$request->attributes->get($key)); }
        }
        if ($request->attributes->has('_partner_replayed')) { $response->headers->set('Idempotency-Replayed',$request->attributes->get('_partner_replayed')?'true':'false'); }
    }
}
