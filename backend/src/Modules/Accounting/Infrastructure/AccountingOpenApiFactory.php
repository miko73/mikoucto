<?php
namespace App\Modules\Accounting\Infrastructure;
use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model;
use ApiPlatform\OpenApi\OpenApi;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

#[AsDecorator('api_platform.openapi.factory')]
final class AccountingOpenApiFactory implements OpenApiFactoryInterface
{
    public function __construct(#[AutowireDecorated] private OpenApiFactoryInterface $inner) { }
    public function __invoke(array $context=[]): OpenApi
    {
        $api=($this->inner)($context);
        $schemas=$api->getComponents()->getSchemas();
        $amount=['type'=>'string','pattern'=>'^(0|[1-9][0-9]{0,15})(\\.[0-9]{1,2})?$','example'=>'100.00'];
        $schemas['DraftLineInput']=new \ArrayObject(['type'=>'object','additionalProperties'=>false,'required'=>['accountId','md','dal'],
            'properties'=>['accountId'=>['type'=>'string','format'=>'uuid'],'text'=>['type'=>'string','maxLength'=>500],'md'=>$amount,'dal'=>$amount]]);
        foreach ($schemas as $name=>$schema) {
            if (str_starts_with($name,'Document.DocumentInput')) {
                $schema=$schema instanceof \ArrayObject?$schema->getArrayCopy():(array)$schema;
                $schema['properties']=[
                    'periodId'=>['type'=>'string','format'=>'uuid'],'postingDate'=>['type'=>'string','format'=>'date'],
                    'text'=>['type'=>'string','minLength'=>1,'maxLength'=>2000],'partnerId'=>['type'=>['string','null'],'format'=>'uuid'],
                    'lines'=>['type'=>'array','maxItems'=>500,'items'=>['$ref'=>'#/components/schemas/DraftLineInput']]];
                $schema['required']=str_contains($name,'jsonMergePatch')?[]:['periodId','postingDate','text'];
                $schema['additionalProperties']=false; $schemas[$name]=$schema;
                continue;
            }
            if (!str_starts_with($name,'Account.AccountInput') && !str_starts_with($name,'Period.PeriodInput')) { continue; }
            $schema=$schema instanceof \ArrayObject?$schema->getArrayCopy():(array)$schema;
            $properties=$schema['properties'] instanceof \ArrayObject?$schema['properties']->getArrayCopy():(array)$schema['properties'];
            foreach ($properties as $field=>$property) {
                $property=(array)$property;
                unset($property['anyOf'],$property['nullable'],$property['default']);
                $property['type']=in_array($field,['active','postable'],true)?'boolean':($field==='parentId'?['string','null']:'string');
                if ($field==='code') { $property['pattern']='^[0-9]{1,20}$'; }
                if ($field==='name') { $property['minLength']=1; $property['maxLength']=200; }
                if ($field==='parentId') { $property['format']='uuid'; }
                if (in_array($field,['startsOn','endsOn'],true)) { $property['format']='date'; }
                $properties[$field]=$property;
            }
            $schema['properties']=$properties; $schema['additionalProperties']=false;
            $schema['required']=str_contains($name,'jsonMergePatch')?[]:(str_starts_with($name,'Account.')?['code','name']:['name','startsOn','endsOn']);
            $schemas[$name]=$schema;
        }
        foreach ($api->getPaths()->getPaths() as $path=>$item) {
            if (!preg_match('~/accounting-units/\{unit\}/(accounts|periods|documents|journal)(?:/|$)~',$path,$match)) { continue; }
            foreach (['get','post','patch'] as $method) {
                $operation=$item->{'get'.ucfirst($method)}(); if ($operation===null) { continue; }
                $reverse=str_ends_with($path,'/reverse');
                $correction=str_ends_with($path,'/correction-draft');
                $createsDocument=$reverse||$correction;
                $commandPath=preg_match('~/(post|close|reverse|correction-draft)$~',$path)===1;
                if ($createsDocument && $method==='post') {
                    $properties=['periodId'=>['type'=>'string','format'=>'uuid'],'postingDate'=>['type'=>'string','format'=>'date']];
                    if ($reverse) { $properties['reason']=['type'=>'string','minLength'=>1,'maxLength'=>1000]; }
                    $operation=$operation->withRequestBody(new Model\RequestBody(required:true,content:new \ArrayObject([
                        'application/json'=>['schema'=>['type'=>'object','additionalProperties'=>false,'required'=>array_keys($properties),'properties'=>$properties]]])));
                } elseif ($commandPath && $method==='post') {
                    $operation=$operation->withRequestBody(new Model\RequestBody(required:true,content:new \ArrayObject([
                        'application/json'=>['schema'=>['type'=>'object','additionalProperties'=>false,'maxProperties'=>0]]])));
                }
                $parameters=$operation->getParameters()??[];
                if ($method==='get') {
                    if (!str_contains($path,'{id}') || str_ends_with($path,'/history')) {
                        foreach (['page'=>['type'=>'integer','minimum'=>1],'itemsPerPage'=>['type'=>'integer','minimum'=>1,'maximum'=>100]] as $key=>$schema) { $parameters[]=new Model\Parameter(name:$key,in:'query',schema:$schema); }
                    }
                    if (!str_contains($path,'{id}')) {
                        $parameters[]=new Model\Parameter(name:'search',in:'query',schema:['type'=>'string','maxLength'=>100]);
                        if (in_array($match[1],['documents','journal'],true)) {
                            foreach (['periodId'=>'uuid','from'=>'date','to'=>'date'] as $field=>$format) { $parameters[]=new Model\Parameter(name:$field,in:'query',schema:['type'=>'string','format'=>$format]); }
                            if ($match[1]==='journal') { $parameters[]=new Model\Parameter(name:'accountId',in:'query',schema:['type'=>'string','format'=>'uuid']); }
                        } else {
                            $parameters[]=$match[1]==='accounts'?new Model\Parameter(name:'active',in:'query',schema:['type'=>'string','enum'=>['true','false']]):new Model\Parameter(name:'on',in:'query',schema:['type'=>'string','format'=>'date']);
                        }
                    }
                }
                $responses=$operation->getResponses()??[]; $status=$method==='post'&&(!$commandPath||$createsDocument)?'201':'200';
                if (isset($responses[$status]) && !str_ends_with($path,'/history') && !str_ends_with($path,'/relations')) {
                    $headers=$responses[$status]->getHeaders()??new \ArrayObject();
                    $header=$method==='get'&&!str_contains($path,'{id}')?'X-Total-Count':'ETag';
                    $headers[$header]=new Model\Header(schema:['type'=>$header==='ETag'?'string':'integer']);
                    if ($method==='post') {
                        if (!$commandPath||$createsDocument) { $headers['Location']=new Model\Header(schema:['type'=>'string']); }
                        $headers['Idempotency-Replayed']=new Model\Header(schema:['type'=>'boolean']);
                    }
                    $responses[$status]=$responses[$status]->withHeaders($headers);
                    if ($createsDocument) { $responses['200']=$responses[$status]->withDescription($reverse?'Existing identical reversal, no new posting.':'Existing correction, current document state; no new draft.'); }
                }
                foreach (['400'=>'Invalid request','401'=>'Authentication required','403'=>'Permission denied','404'=>'Not found','409'=>'Accounting or idempotency conflict','422'=>'Invalid input'] as $code=>$description) { $responses[$code]=new Model\Response($description); }
                if ($createsDocument) { $responses['409']=$responses['409']->withHeaders(new \ArrayObject(['Location'=>new Model\Header(description:'Existing document when different values were requested.',schema:['type'=>'string'])])); }
                if ($method==='patch'||$commandPath) { $responses['412']=new Model\Response('Stale ETag'); $responses['428']=new Model\Response('If-Match required'); }
                $unique=[];
                foreach ($parameters as $parameter) { $unique[$parameter->getIn().':'.$parameter->getName()]=$parameter; }
                $item=$item->{'with'.ucfirst($method)}($operation->withParameters(array_values($unique))->withResponses($responses));
            }
            $api->getPaths()->addPath($path,$item);
        }
        $api=$api->withComponents($api->getComponents()->withSchemas($schemas));
        ReportOpenApi::decorate($api);
        return $api;
    }
}
