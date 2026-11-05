<?php
// Test-only subprocess. Credentials stay in the inherited process environment.
require dirname(__DIR__).'/bootstrap.php';
use App\Tests\Support\TestDatabase;
use App\Tests\Support\TestTokens;
use App\Modules\Core\Application\Identity\AccessTokenValidator;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\HttpFoundation\Request;

$input=json_decode(fgets(STDIN),true,512,JSON_THROW_ON_ERROR);
$connection=TestDatabase::connection('app');
$write=static function(string $suffix,array $value) use($input): void {
    $path=$input['prefix'].'.'.$suffix;
    file_put_contents($path.'.tmp',json_encode($value,JSON_THROW_ON_ERROR)); rename($path.'.tmp',$path);
};
$kernel=null; $accessToken=null;
try {
    $connection->executeStatement("SET statement_timeout='75s'");
    $connection->executeStatement("SET lock_timeout='60s'");
    if ($input['action']==='http') {
        $kernel=new App\Kernel('test',false); $kernel->boot();
        $container=$kernel->getContainer()->get('test.service_container');
        $container->set(Connection::class,$connection);
        $container->set(AccessTokenValidator::class,TestTokens::validator());
        // Keep the cause of an HTTP 500 even when the public error response
        // deliberately hides it. This listener observes; it does not handle it.
        $container->get('event_dispatcher')->addListener(\Symfony\Component\HttpKernel\KernelEvents::EXCEPTION,
            static function(\Symfony\Component\HttpKernel\Event\ExceptionEvent $event) use($write): void {
                $error=$event->getThrowable();
                $write('exception',['class'=>get_class($error),'message'=>$error->getMessage(),
                    'trace'=>array_map(static fn(array $frame): array=>array_intersect_key($frame,array_flip(['file','line','class','function'])),$error->getTrace())]);
            },1024);
        // RSA generation belongs to preparation, not to the timed lock test.
        $accessToken=TestTokens::token(['oid'=>$input['object']]);
        // Prepare routing, authorization and serialization before the timed
        // barrier. This GET never performs an accounting write.
        $resource=explode('/',ltrim($input['suffix']??'','/'))[0];
        $warmup=Request::create('/api/v1/accounting-units/'.$input['unit'].'/'.$input['kind'].($resource===''?'':'/'.$resource),'GET',server:[
            'HTTP_AUTHORIZATION'=>'Bearer '.$accessToken,'HTTP_ACCEPT'=>'application/json']);
        $warmupResponse=$kernel->handle($warmup);
        if ($warmupResponse->getStatusCode()!==200) { throw new RuntimeException('Worker read warmup failed: '.$warmupResponse->getStatusCode()); }
        $kernel->terminate($warmup,$warmupResponse);
    }
    $write('ready',['pid'=>(int)$connection->fetchOne('SELECT pg_backend_pid()')]);
    // The first prepared process may wait while its peer builds cold metadata.
    // The actual lock test starts only when both have received their go file.
    $deadline=microtime(true)+240;
    while (!is_file($input['prefix'].'.go')) { if (microtime(true)>$deadline) { throw new RuntimeException('Barrier timeout.'); } usleep(20000); clearstatcache(); }
    if ($kernel!==null) {
        $method=$input['method']??'POST';
        $request=Request::create('/api/v1/accounting-units/'.$input['unit'].'/'.$input['kind'].($input['suffix']??''),$method,server:[
            'HTTP_AUTHORIZATION'=>'Bearer '.$accessToken,
            'HTTP_IDEMPOTENCY_KEY'=>$input['key']??'','HTTP_IF_MATCH'=>$input['etag']??'',
            'HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>$method==='PATCH'?'application/merge-patch+json':'application/json'
        ],content:$input['rawBody']??json_encode($input['payload'],JSON_THROW_ON_ERROR));
        $response=$kernel->handle($request);
        $write('done',['status'=>$response->getStatusCode(),'body'=>json_decode($response->getContent(),true),'replayed'=>$response->headers->get('Idempotency-Replayed')]);
        $kernel->terminate($request,$response);
    } else {
        $connection->transactional(function(Connection $c) use($input): void {
            $c->fetchOne('SELECT core.set_context(?::uuid,?,?)',[$input['user'],'concurrent-accounting','test']);
            $c->fetchOne("SELECT set_config('app.accounting_unit_id',?,true)",[$input['unit']]);
            $c->executeStatement($input['sql'],$input['params']);
        });
        $write('done',['state'=>'OK']);
    }
} catch (Throwable $e) {
    $write('done',['state'=>$e instanceof Exception?$e->getSQLState():get_class($e),
        'preparationError'=>get_class($e)===RuntimeException::class?$e->getMessage():null]);
} finally { if ($kernel!==null) { $kernel->shutdown(); } $connection->close(); }
