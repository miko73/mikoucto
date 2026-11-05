<?php
namespace App\Modules\Accounting\ApiResource;
use ApiPlatform\Metadata\ApiProperty;
use App\Modules\Accounting\Application\DecimalAmount;
class DocumentSummary
{
    public function __construct(
        #[ApiProperty(identifier:true)] public string $id,
        #[ApiProperty(identifier:false)] public string $accountingUnitId,
        public string $periodId,public string $postingDate,public string $text,public ?string $partnerId,
        public string $status,public string $currency,public ?string $number,
        public string $totalMd,public string $totalDal,public string $difference,public bool $balanced,
        public int $version,public string $createdAt,public string $updatedAt,
        public ?string $postedAt=null,public ?string $postedBy=null
    ) { }
    public static function fromRow(array $row): static
    {
        $difference=DecimalAmount::difference($row['total_md'],$row['total_dal']);
        return new static($row['id'],$row['accounting_unit_id'],$row['period_id'],$row['posting_date'],$row['text'],$row['partner_id'],
            $row['status'],$row['currency'],$row['number']??null,$row['total_md'],$row['total_dal'],$difference,$difference==='0.00',
            (int)$row['version'],$row['created_at'],$row['updated_at'],$row['posted_at']??null,$row['posted_by']??null);
    }
    public function etag(): string { return '"'.$this->id.':'.$this->version.'"'; }
}
