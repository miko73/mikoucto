<?php

namespace App\Modules\Core\Infrastructure\Identity;

use Doctrine\DBAL\Exception;
use App\Modules\Core\Application\InputValidationException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Uid\Uuid;

final class ApiProblemSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['kernel.request' => ['onRequest', 2048], 'kernel.response' => 'onResponse', 'kernel.exception' => ['onException', 32]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) { return; }
        $request = $event->getRequest();
        $provided = $request->headers->get('X-Request-Id', '');
        $request->attributes->set('_request_id', preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $provided) === 1 ? $provided : Uuid::v7()->toRfc4122());
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            $event->getResponse()->headers->set('X-Request-Id', $event->getRequest()->attributes->get('_request_id', ''));
            $event->getResponse()->headers->set('Cache-Control', 'no-store');
        }
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) { return; }
        $exception = $event->getThrowable();
        if ($exception instanceof InputValidationException) {
            $status=$exception->getStatusCode();
            $event->setResponse(new JsonResponse(['type'=>'about:blank','title'=>$exception->title,'status'=>$status,'violations'=>$exception->violations],$status,['Content-Type'=>'application/problem+json']));
        } elseif ($exception instanceof Exception) {
            $status = match ($exception->getSQLState()) { '42501' => 403, '22023' => 400, '23505', '23514' => 409, default => 503 };
            $event->setResponse(BearerAuthenticator::problem($status, match ($status) {
                403 => 'Permission denied', 400 => 'Invalid user or role', 409 => 'Operation conflicts with access rules', default => 'Database unavailable',
            }));
        } elseif ($exception instanceof IdentityConfigurationException) {
            $event->setResponse(BearerAuthenticator::problem(503, 'Access service unavailable'));
        } elseif ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();
            $event->setResponse(BearerAuthenticator::problem($status, match ($status) { 400 => 'Invalid request', 403 => 'Permission denied', 404 => 'Not found', 409=>'Conflict', 412=>'Resource version does not match', 428=>'If-Match is required', default => 'Request failed' }));
            foreach ($exception->getHeaders() as $name=>$value) { $event->getResponse()->headers->set($name,$value); }
        }
    }
}
