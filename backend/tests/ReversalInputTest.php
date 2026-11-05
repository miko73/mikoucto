<?php
namespace App\Tests;
use App\Modules\Accounting\Application\ReversalInput;
use App\Modules\Core\Application\InputValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class ReversalInputTest extends TestCase
{
    private const INPUT=['periodId'=>'019A4D12-1234-7123-8123-123456789ABC','postingDate'=>'2027-02-05','reason'=>'  Oprava částky  '];
    public function testCanonicalInputHasOnlyClientFields(): void
    {
        self::assertSame(['periodId'=>strtolower(self::INPUT['periodId']),'postingDate'=>'2027-02-05','reason'=>'Oprava částky'],ReversalInput::normalize(self::INPUT));
    }
    #[DataProvider('invalid')]
    public function testInvalidInputIsRejected(array $changes): void
    {
        $this->expectException(InputValidationException::class); ReversalInput::normalize(array_replace(self::INPUT,$changes));
    }
    public static function invalid(): iterable
    {
        foreach ([['periodId'=>null],['periodId'=>'x'],['postingDate'=>'2027-02-30'],['postingDate'=>42],['reason'=>'  '],['reason'=>['x']],['reason'=>str_repeat('ž',1001)],['reason'=>"x\0y"],['lines'=>[]],['createdBy'=>'x']] as $changes) { yield [$changes]; }
    }
}
