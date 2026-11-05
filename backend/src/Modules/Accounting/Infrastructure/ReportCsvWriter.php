<?php
namespace App\Modules\Accounting\Infrastructure;

final class ReportCsvWriter
{
    public const BASIS='Výpočet z evidovaných zaúčtovaných pohybů vybraného období. Převod počátečních stavů z jiných období není zahrnut.';
    public static function text(string $value): string
    {
        // Protect spreadsheet text cells, including formulas hidden behind
        // leading whitespace. Numeric amount cells deliberately bypass this.
        return preg_match('/^(?:[\x00-\x20\p{Z}]*[=+\-@\x{FF1D}\x{FF0B}\x{FF0D}\x{FF20}]|[\t\r\n])/u',$value)?"'".$value:$value;
    }
    public static function money(string $value): string
    {
        if (!preg_match('/^-?(0|[1-9][0-9]*)\.[0-9]{2}$/D',$value)) { throw new \UnexpectedValueException('Noncanonical report amount.'); }
        return str_replace('.',',',$value);
    }
    private function put($stream,array $fields): void
    {
        if (fputcsv($stream,$fields,';','"','',"\r\n")===false) { throw new \RuntimeException('Cannot write report CSV.'); }
    }
    public function begin($stream,array $metadata,array $unit,bool $ledger): void
    {
        if (fwrite($stream,"\xEF\xBB\xBF")!==3) { throw new \RuntimeException('Cannot write report BOM.'); }
        $f=$metadata['filters'];
        foreach ([['Sestava',$ledger?'Hlavní kniha':'Obratová předvaha'],['Jednotka',self::text($unit['code'])],
            ['Název jednotky',self::text($unit['name'])],['ID jednotky',$unit['id']],['Období',self::text($f['periodName'])],
            ['ID období',$f['periodId']],['Od',$f['from']],['Do',$f['to']],['Sestaveno UTC',$metadata['generatedAt']],['Měna','CZK'],['Základ výpočtu',self::BASIS]] as $fields) { $this->put($stream,$fields); }
        if ($ledger) { $this->put($stream,['Účet',self::text($f['accountCode'])]); $this->put($stream,['Název účtu',self::text($f['accountName'])]); }
        else { $this->put($stream,['Zahrnuty účty bez pohybů',$f['includeZero']?'ANO':'NE']); }
        $this->put($stream,[]);
        $this->put($stream,$this->header($ledger));
    }
    public function header(bool $ledger): array
    {
        return $ledger?['Datum účtování','Číslo dokladu','Účet','Popis dokladu','Popis řádku','MD','DAL','Průběžný zůstatek MD − DAL','Pořadí řádku']:
            ['Kód účtu','Název účtu','Počáteční MD','Počáteční DAL','Obrat MD','Obrat DAL','Konečné MD','Konečné DAL'];
    }
    public function row($stream,array $item,array $metadata,bool $ledger): void
    {
        $fields=$ledger?[$item['postingDate'],self::text($item['number']),self::text($metadata['filters']['accountCode']),
            self::text($item['documentText']),self::text($item['text']),self::money($item['md']),self::money($item['dal']),self::money($item['runningBalance']),(string)$item['lineNo']]:
            [self::text($item['accountCode']),self::text($item['accountName']),...array_map(self::money(...),array_map(static fn($key)=>$item[$key],['openingMd','openingDal','turnoverMd','turnoverDal','closingMd','closingDal']))];
        $this->put($stream,$fields);
    }
    public function finish($stream,array $metadata,bool $ledger): void
    {
        $this->put($stream,[]); $this->put($stream,['Souhrn']); $t=$metadata['totals'];
        if ($ledger) {
            foreach (['openingBalance'=>'Počáteční zůstatek MD − DAL','turnoverMd'=>'Obrat MD','turnoverDal'=>'Obrat DAL','closingBalance'=>'Konečný zůstatek MD − DAL'] as $key=>$label) { $this->put($stream,[$label,self::money($t[$key])]); }
        } else {
            $this->put($stream,$this->header(false));
            $this->put($stream,['CELKEM','',...array_map(self::money(...),array_map(static fn($key)=>$t[$key],['openingMd','openingDal','turnoverMd','turnoverDal','closingMd','closingDal']))]);
            foreach (['openingDifference'=>'Rozdíl počátečních zůstatků MD − DAL','turnoverDifference'=>'Rozdíl obratů MD − DAL','closingDifference'=>'Rozdíl konečných zůstatků MD − DAL'] as $key=>$label) { $this->put($stream,[$label,self::money($t[$key])]); }
            $this->put($stream,['Vyrovnaná předvaha',$t['balanced']?'ANO':'NE']);
        }
        $this->put($stream,['Počet datových řádků',(string)$metadata['total']]);
    }
}
