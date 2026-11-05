<?php

namespace App\Modules\Partners\Infrastructure;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model;
use ApiPlatform\OpenApi\OpenApi;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Intl\Countries;

#[AsDecorator('api_platform.openapi.factory')]
final class PartnerOpenApiFactory implements OpenApiFactoryInterface
{
    public function __construct(#[AutowireDecorated] private OpenApiFactoryInterface $inner) { }

    public function __invoke(array $context=[]): OpenApi
    {
        $api=($this->inner)($context);
        $schemes=$api->getComponents()->getSecuritySchemes()??new \ArrayObject();
        $schemes['bearerAuth']=new Model\SecurityScheme(type:'http',scheme:'bearer',bearerFormat:'JWT');
        $schemas=$api->getComponents()->getSchemas();
        foreach ($schemas as $name=>$definition) {
            if (!str_starts_with($name,'Partner.PartnerInput')) { continue; }
            $definition=$definition instanceof \ArrayObject?$definition->getArrayCopy():(array)$definition;
            $properties=$definition['properties'] instanceof \ArrayObject?$definition['properties']->getArrayCopy():(array)$definition['properties'];
            foreach (['name'=>'string','countryCode'=>'string','active'=>'boolean'] as $field=>$type) {
                $properties[$field]=(array)$properties[$field];
                $properties[$field]['type']=$type;
            }
            $properties['name']['minLength']=1;
            $properties['countryCode']['enum']=Countries::getCountryCodes();
            $properties['vatNumber']=(array)$properties['vatNumber'];
            $properties['vatNumber']['pattern']='^[A-Z]{2}[A-Z0-9]{2,30}$';
            if (!str_contains($name,'jsonMergePatch')) {
                $definition['required']=['name'];
                $properties['countryCode']['default']='CZ';
                $properties['active']['default']=true;
            }
            $definition['properties']=$properties;
            $schemas[$name]=$definition;
        }
        foreach ($api->getPaths()->getPaths() as $path=>$item) {
            if (!str_contains($path,'/partners')) { continue; }
            foreach (['get','post','patch'] as $method) {
                $operation=$item->{'get'.ucfirst($method)}();
                if ($operation===null) { continue; }
                $responses=$operation->getResponses()??[];
                $status=$method==='post'?'201':'200';
                if (isset($responses[$status])) {
                    $headers=$responses[$status]->getHeaders()??new \ArrayObject();
                    if (!str_ends_with($path,'/history')) {
                        $header=($method==='get' && !str_contains($path,'{id}'))?'X-Total-Count':'ETag';
                        $headers[$header]=new Model\Header(description:$header==='ETag'?'Strong version for If-Match':'Number of matching partners',schema:['type'=>$header==='ETag'?'string':'integer']);
                    }
                    if ($method==='post') { $headers['Idempotency-Replayed']=new Model\Header(schema:['type'=>'boolean']); }
                    $responses[$status]=$responses[$status]->withHeaders($headers);
                }
                foreach (['401'=>'Access token required','403'=>'Permission denied','409'=>'Duplicate registration number or idempotency conflict'] as $code=>$description) {
                    $responses[$code]=new Model\Response($description);
                }
                if ($method==='patch') { $responses['412']=new Model\Response('Stale ETag'); $responses['428']=new Model\Response('If-Match required'); }
                $item=$item->{'with'.ucfirst($method)}($operation->withResponses($responses));
            }
            $api->getPaths()->addPath($path,$item);
        }
        // Pouzivame JSON Schema 2020-12 a pouze operace/objekty spolecne s OpenAPI 3.1.
        return $api->withOpenapi('3.1.0')->withComponents($api->getComponents()->withSchemas($schemas)->withSecuritySchemes($schemes))->withSecurity([['bearerAuth'=>[]]]);
    }
}
