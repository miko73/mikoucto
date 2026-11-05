<?php
namespace App\Modules\Accounting\State;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
final class AccountingProcessor implements ProcessorInterface
{
    public function __construct(private AccountingService $service) { }
    public function process(mixed $data,Operation $operation,array $uriVariables=[],array $context=[]): mixed
    {
        return $this->service->write(AccountingService::kind($operation),$uriVariables,$operation instanceof Post);
    }
}
