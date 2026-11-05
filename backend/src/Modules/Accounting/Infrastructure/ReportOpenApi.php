<?php

namespace App\Modules\Accounting\Infrastructure;

use ApiPlatform\OpenApi\Model;
use ApiPlatform\OpenApi\OpenApi;

/** Report envelopes deliberately differ from the existing flat collections. */
final class ReportOpenApi
{
    public static function decorate(OpenApi $api): void
    {
        $schemas=$api->getComponents()->getSchemas();
        $string=['type'=>'string']; $uuid=['type'=>'string','format'=>'uuid'];
        $amount=['type'=>'string','pattern'=>'^-?(0|[1-9][0-9]*)\\.[0-9]{2}$','example'=>'150.00'];
        $object=static fn(array $properties)=>['type'=>'object','additionalProperties'=>false,'required'=>array_keys($properties),'properties'=>$properties];
        $filters=$object(['periodId'=>$uuid,'periodName'=>$string,'periodStartsOn'=>['type'=>'string','format'=>'date'],
            'periodEndsOn'=>['type'=>'string','format'=>'date'],'from'=>['type'=>'string','format'=>'date'],'to'=>['type'=>'string','format'=>'date'],
            'includeZero'=>['type'=>'boolean'],'accountId'=>['type'=>['string','null'],'format'=>'uuid'],
            'accountCode'=>['type'=>['string','null']],'accountName'=>['type'=>['string','null']]]);
        foreach (['general-ledger','trial-balance'] as $kind) {
            $ledger=$kind==='general-ledger'; $name=$ledger?'GeneralLedgerReport':'TrialBalanceReport';
            $totals=[];
            foreach ($ledger?['openingBalance','turnoverMd','turnoverDal','closingBalance']:
                ['openingMd','openingDal','turnoverMd','turnoverDal','closingMd','closingDal','openingDifference','turnoverDifference','closingDifference'] as $key) { $totals[$key]=$amount; }
            if (!$ledger) { $totals['balanced']=['type'=>'boolean']; }
            $row=$ledger?['id'=>$uuid,'accountId'=>$uuid,'documentId'=>$uuid,'number'=>$string,'postingDate'=>['type'=>'string','format'=>'date'],
                'lineNo'=>['type'=>'integer','minimum'=>1],'documentText'=>$string,'text'=>$string,'md'=>$amount,'dal'=>$amount,'runningBalance'=>$amount]:
                ['accountId'=>$uuid,'accountCode'=>$string,'accountName'=>$string,...array_intersect_key($totals,array_flip(['openingMd','openingDal','turnoverMd','turnoverDal','closingMd','closingDal']))];
            $schemas[$name]=new \ArrayObject($object(['accountingUnitId'=>$uuid,'filters'=>$filters,'generatedAt'=>['type'=>'string','format'=>'date-time'],
                'currency'=>['type'=>'string','enum'=>['CZK']],'basis'=>['type'=>'string','enum'=>['posted_movements_within_period_no_carry_forward']],
                'totals'=>$object($totals),'items'=>['type'=>'array','items'=>$object($row)],'total'=>['type'=>'integer','minimum'=>0],
                'page'=>['type'=>'integer','minimum'=>1,'maximum'=>100000],'itemsPerPage'=>['type'=>'integer','minimum'=>1,'maximum'=>100]]));
            $path='/api/v1/accounting-units/{unit}/reports/'.$kind;
            $item=$api->getPaths()->getPath($path);
            if ($item===null||$item->getGet()===null) { continue; }
            $operation=$item->getGet();
            $parameters=array_filter($operation->getParameters()??[],static fn($p)=>$p->getIn()!=='query');
            foreach (['periodId'=>'uuid',...($ledger?['accountId'=>'uuid']:[]),'from'=>'date','to'=>'date'] as $key=>$format) {
                $parameters[]=new Model\Parameter(name:$key,in:'query',required:in_array($key,['periodId','accountId'],true),schema:['type'=>'string','format'=>$format]);
            }
            foreach (['page'=>100000,'itemsPerPage'=>100] as $key=>$max) {
                $parameters[]=new Model\Parameter(name:$key,in:'query',schema:['type'=>'integer','minimum'=>1,'maximum'=>$max,'default'=>$key==='page'?1:20]);
            }
            if (!$ledger) { $parameters[]=new Model\Parameter(name:'includeZero',in:'query',schema:['type'=>'string','enum'=>['true','false'],'default'=>'false']); }
            $responses=['200'=>new Model\Response(description:'Posted movements within one period; no automatic carry-forward. Totals cover all pages.',
                headers:new \ArrayObject(['Cache-Control'=>new Model\Header(schema:['type'=>'string','example'=>'private, no-store'])]),
                content:new \ArrayObject(['application/json'=>['schema'=>['$ref'=>'#/components/schemas/'.$name]]]))];
            foreach (['400'=>'Invalid filter or date outside period','401'=>'Authentication required','403'=>'Read permissions required','404'=>'Period or account not found in unit'] as $status=>$description) {
                $responses[$status]=new Model\Response($description);
            }
            $api->getPaths()->addPath($path,$item->withGet($operation->withParameters(array_values($parameters))->withResponses($responses)));
            $csvPath=$path.'.csv'; $csvItem=$api->getPaths()->getPath($csvPath);
            if ($csvItem!==null&&$csvItem->getGet()!==null) {
                $csvParameters=array_values(array_filter($parameters,static fn($p)=>!in_array($p->getName(),['page','itemsPerPage'],true)));
                $csvResponses=$responses;
                $csvResponses['200']=new Model\Response(description:'Complete selection, at most 50000 data rows. UTF-8 BOM, semicolon, CRLF, Czech decimal comma. No automatic carry-forward.',
                    headers:new \ArrayObject(['Content-Disposition'=>new Model\Header(schema:['type'=>'string','example'=>'attachment; filename="general-ledger.csv"']),
                        'Cache-Control'=>new Model\Header(schema:['type'=>'string','example'=>'private, no-store'])]),
                    content:new \ArrayObject(['text/csv'=>['schema'=>['type'=>'string','format'=>'binary']]]));
                $csvResponses['422']=new Model\Response('Selection exceeds 50000 rows. Narrow the date range; no partial file is sent.');
                $api->getPaths()->addPath($csvPath,$csvItem->withGet($csvItem->getGet()->withParameters($csvParameters)->withResponses($csvResponses)));
            }
        }
    }
}
