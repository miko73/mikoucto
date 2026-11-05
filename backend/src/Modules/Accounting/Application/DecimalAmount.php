<?php
namespace App\Modules\Accounting\Application;

use App\Modules\Core\Application\InputValidationException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** Exact CZK amounts; no binary floating point or native integer totals. */
final class DecimalAmount
{
    public static function normalize(mixed $value): string
    {
        if (!is_string($value)) { throw new BadRequestHttpException('Amounts must be strings.'); }
        if (preg_match('/^(0|[1-9][0-9]{0,15})(?:\.([0-9]{1,2}))?$/D',$value,$parts)!==1) {
            throw new InputValidationException(['amount'=>'Use a nonnegative decimal string with at most two decimal places.']);
        }
        return $parts[1].'.'.str_pad($parts[2]??'',2,'0');
    }
    public static function sum(array $amounts): string
    {
        $sum='0';
        foreach ($amounts as $amount) { $sum=self::add($sum,self::cents(self::normalize($amount))); }
        return self::format($sum);
    }
    public static function difference(string $md,string $dal): string
    {
        $a=self::cents($md); $b=self::cents($dal);
        $compare=strlen($a)<=>strlen($b); if ($compare===0) { $compare=strcmp($a,$b); }
        if ($compare===0) { return '0.00'; }
        $negative=$compare<0; if ($negative) { [$a,$b]=[$b,$a]; }
        $out=''; $borrow=0; $j=strlen($b)-1;
        for ($i=strlen($a)-1;$i>=0;--$i,--$j) {
            $digit=(int)$a[$i]-($j>=0?(int)$b[$j]:0)-$borrow;
            $borrow=$digit<0?1:0; $out=(string)(($digit+10)%10).$out;
        }
        return ($negative?'-':'').self::format($out);
    }
    private static function cents(string $amount): string { return ltrim(str_replace('.','',$amount),'0')?:'0'; }
    private static function format(string $digits): string
    {
        $digits=str_pad(ltrim($digits,'0')?:'0',3,'0',STR_PAD_LEFT);
        return substr($digits,0,-2).'.'.substr($digits,-2);
    }
    private static function add(string $a,string $b): string
    {
        $result=''; $carry=0; $i=strlen($a)-1; $j=strlen($b)-1;
        while ($i>=0 || $j>=0 || $carry) {
            $n=($i>=0?(int)$a[$i--]:0)+($j>=0?(int)$b[$j--]:0)+$carry;
            $result=(string)($n%10).$result; $carry=intdiv($n,10);
        }
        return $result;
    }
}
