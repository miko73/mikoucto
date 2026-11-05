<?php
namespace App\Modules\Accounting\State;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
final class PostingProcessor implements ProcessorInterface
{
    public function __construct(private PostingService $service) { }
    public function process(mixed $data,Operation $operation,array $uriVariables=[],array $context=[]): mixed
    {
        return $this->service->execute($operation->getName()==='documents_post'?'post':'close',$uriVariables);
    }
}
