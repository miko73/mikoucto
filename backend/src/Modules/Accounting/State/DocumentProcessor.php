<?php
namespace App\Modules\Accounting\State;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
final class DocumentProcessor implements ProcessorInterface
{
    public function __construct(private DocumentService $service) { }
    public function process(mixed $data,Operation $operation,array $uriVariables=[],array $context=[]): mixed
    {
        return $this->service->write($uriVariables,$operation instanceof Post);
    }
}
