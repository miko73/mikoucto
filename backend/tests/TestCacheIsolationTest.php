<?php
namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class TestCacheIsolationTest extends KernelTestCase
{
    public function testConcurrentWorkersUseProcessLocalPropertyMetadataCache(): void
    {
        self::bootKernel();
        self::assertInstanceOf(ArrayAdapter::class,self::getContainer()->get('cache.property_info'));
    }
}
