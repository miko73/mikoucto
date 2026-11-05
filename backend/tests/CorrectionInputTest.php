<?php
namespace App\Tests;
use App\Modules\Accounting\Application\CorrectionInput;
use App\Modules\Core\Application\InputValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class CorrectionInputTest extends TestCase
{
    private const INPUT=['periodId'=>'019A4D12-1234-7123-8123-123456789ABC','postingDate'=>'2027-02-05'];
    public function testCanonicalInputContainsOnlyTargetPeriodAndDate(): void
    {
        self::assertSame(['periodId'=>strtolower(self::INPUT['periodId']),'postingDate'=>'2027-02-05'],CorrectionInput::normalize(self::INPUT));
    }
    #[DataProvider('invalid')]
    public function testInvalidOrServerManagedInputIsRejected(array $input): void
    {
        $this->expectException(InputValidationException::class); CorrectionInput::normalize($input);
    }
    public static function invalid(): iterable
    {
        yield [[]];
        foreach ([['periodId'=>null],['periodId'=>[]],['periodId'=>'x'],['postingDate'=>'2027-02-30'],['postingDate'=>42],
            ['reason'=>'reason'],['lines'=>[]],['originalDocumentId'=>'x'],['reversalId'=>'x'],['correction'=>[]],['createdBy'=>'x']] as $changes) {
            yield [array_replace(self::INPUT,$changes)];
        }
    }
}
