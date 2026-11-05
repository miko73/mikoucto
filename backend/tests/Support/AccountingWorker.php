<?php
namespace App\Tests\Support;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Assert;
final class AccountingWorker
{
    private mixed $process;
    private string $prefix;
    private bool $keepDiagnostics=false;
    public readonly int $pid;
    public function __construct(array $input)
    {
        $this->prefix=dirname(__DIR__,2).'/var/accounting-worker-'.bin2hex(random_bytes(12));
        $input['prefix']=$this->prefix;
        // PHP on Windows can recode a Unicode script argument using the ANSI
        // code page. Pass the path as base64 and decode it inside PHP instead.
        $this->process=proc_open([PHP_BINARY,'-r','require base64_decode($argv[1]);',base64_encode(__DIR__.'/accounting-worker.php')],[0=>['pipe','r'],1=>['file',PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null','w'],2=>['file',$this->prefix.'.stderr','w']],$pipes,dirname(PHP_BINARY),null,['bypass_shell'=>true]);
        if (!is_resource($this->process)) { throw new \RuntimeException('Cannot start accounting worker.'); }
        fwrite($pipes[0],json_encode($input,JSON_THROW_ON_ERROR)."\n"); fclose($pipes[0]);
        try { $this->pid=$this->wait('ready')['pid']; }
        catch (\Throwable $error) { $this->close(); throw $error; }
    }
    public function go(): void { file_put_contents($this->prefix.'.go','go'); }
    public function result(): array { return $this->wait('done'); }
    public function retainDiagnostics(): string { $this->keepDiagnostics=true; return $this->prefix; }
    public function completed(): ?array
    {
        clearstatcache();
        $path=$this->prefix.'.done';
        if (!is_file($path)) { return null; }
        $result=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        return array_intersect_key($result,array_flip(['status','state']));
    }
    public static function awaitBlockedBy(Connection $observer,Connection $blocker,self ...$workers): void
    {
        if ($workers===[]) { throw new \InvalidArgumentException('At least one worker is required.'); }
        $blockerPid=(int)$blocker->fetchOne('SELECT pg_backend_pid()');
        $pids=array_map(static fn(self $worker): int=>$worker->pid,$workers);
        // Observe all workers in the same query. The shared deadline leaves
        // headroom before the workers' 60-second lock timeout, including slow
        // HTTP preparation. A finished request is a failure, never a retry.
        $deadline=microtime(true)+45; $waiting=[];
        do {
            $waiting=$observer->fetchFirstColumn(
                'SELECT pid FROM pg_stat_activity WHERE pid IN (?) AND ?=ANY(pg_blocking_pids(pid))',
                [$pids,$blockerPid],[ArrayParameterType::INTEGER,\Doctrine\DBAL\ParameterType::INTEGER]);
            if (count($waiting)===count($workers)) { break; }
            foreach ($workers as $worker) {
                if (($result=$worker->completed())!==null) {
                    Assert::fail('Worker '.$worker->pid.' completed before all workers waited for the barrier: '.json_encode($result,JSON_THROW_ON_ERROR).'. Diagnostics: '.$worker->retainDiagnostics().'.*');
                }
            }
            usleep(50000);
        } while (microtime(true)<$deadline);
        $activity=[]; $diagnostics=[];
        if (count($waiting)!==count($workers)) {
            foreach ($workers as $worker) { $diagnostics[]=$worker->retainDiagnostics().'.*'; }
            $observer->executeStatement('SELECT pg_stat_clear_snapshot()');
            $activity=$observer->fetchAllAssociative(
                'SELECT pid,state,wait_event_type,wait_event,pg_blocking_pids(pid) AS blockers FROM pg_stat_activity WHERE pid IN (?)',
                [$pids],[ArrayParameterType::INTEGER]);
        }
        Assert::assertCount(count($workers),$waiting,
            'All workers must actually wait for barrier PID '.$blockerPid.'. Activity: '.json_encode($activity,JSON_THROW_ON_ERROR).'. Diagnostics: '.implode(', ',$diagnostics));
    }
    private function wait(string $suffix): array
    {
        // Cold HTTP metadata is prepared before "ready"; keep this setup
        // deadline separate from the result and database-lock deadlines.
        $deadline=microtime(true)+($suffix==='ready'?180:90);
        do {
            clearstatcache();
            if (is_file($this->prefix.'.'.$suffix)) { return json_decode(file_get_contents($this->prefix.'.'.$suffix),true,512,JSON_THROW_ON_ERROR); }
            if (!proc_get_status($this->process)['running']) { throw new \RuntimeException('Accounting worker ended before '.$suffix.'. Diagnostics: '.$this->retainDiagnostics().'.*'); }
            usleep(50000);
        } while (microtime(true)<$deadline);
        throw new \RuntimeException('Accounting worker timeout at '.$suffix.'. Diagnostics: '.$this->retainDiagnostics().'.*');
    }
    public function close(): void
    {
        if (is_resource($this->process)) {
            if (proc_get_status($this->process)['running']) { proc_terminate($this->process); }
            proc_close($this->process);
        }
        if (!$this->keepDiagnostics) { foreach (glob($this->prefix.'.*') as $file) { unlink($file); } }
    }
    public function __destruct() { $this->close(); }
}
