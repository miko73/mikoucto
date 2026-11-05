<?php

// Vyvojovy server poskytuje pouze vstupni bod API.
$started=hrtime(true);
register_shutdown_function(static function() use($started): void {
    // Development diagnostics only: never record tokens, query strings, bodies
    // or customer identifiers. Unknown URL segments are redacted.
    $known=['api','v1','health','database','me','accounting-units','permissions','partners','accounts','periods','documents','journal','history','post','close'];
    $path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';
    $parts=array_map(static fn($part)=>in_array($part,$known,true)?$part:'{id}',explode('/',trim($path,'/')));
    $entry=['at'=>gmdate('c'),'method'=>in_array($_SERVER['REQUEST_METHOD']??'', ['GET','POST','PATCH','PUT','DELETE','OPTIONS'],true)?$_SERVER['REQUEST_METHOD']:'OTHER',
        'route'=>'/'.implode('/',$parts),'status'=>http_response_code()?:200,'milliseconds'=>round((hrtime(true)-$started)/1000000,1)];
    $log=dirname(__DIR__).'/var/dev-requests.log';
    if (is_file($log)&&filesize($log)>5*1024*1024) { @rename($log,$log.'.1'); }
    @file_put_contents($log,json_encode($entry,JSON_UNESCAPED_SLASHES)."\n",FILE_APPEND|LOCK_EX);
});
require __DIR__.'/index.php';
