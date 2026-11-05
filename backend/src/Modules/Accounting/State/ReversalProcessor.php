<?php
namespace App\Modules\Accounting\State;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
final class ReversalProcessor implements ProcessorInterface
{
    public function __construct(private ReversalService $service) { }
    public function process(mixed $data,Operation $operation,array $uriVariables=[],array $context=[]): mixed { return $this->service->execute($uriVariables); }
}
