<?php

namespace App\Modules\Accounting\Infrastructure;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

final class AccountingResponseSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array { return ['kernel.response'=>['onResponse',-128]]; }
    public function onResponse(ResponseEvent $event): void
    {
        $request=$event->getRequest(); $response=$event->getResponse();
        if ($request->attributes->has('_accounting_report') || $request->attributes->has('_accounting_no_store')) { $response->headers->set('Cache-Control','private, no-store'); }
        if ($request->attributes->has('_accounting_no_store') && !$request->attributes->has('_accounting_etag')) { $response->headers->remove('ETag'); }
        if (!$response->isSuccessful()) { return; }
        if ($request->attributes->has('_accounting_status')) { $response->setStatusCode($request->attributes->getInt('_accounting_status')); }
        foreach (['_accounting_etag'=>'ETag','_accounting_location'=>'Location','_accounting_total'=>'X-Total-Count'] as $key=>$header) {
            if ($request->attributes->has($key)) { $response->headers->set($header,(string)$request->attributes->get($key)); }
        }
        if ($request->attributes->has('_accounting_replayed')) { $response->headers->set('Idempotency-Replayed',$request->attributes->get('_accounting_replayed')?'true':'false'); }
    }
}
