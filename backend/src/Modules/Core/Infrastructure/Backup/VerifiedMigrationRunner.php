<?php
namespace App\Modules\Core\Infrastructure\Backup;
use App\Modules\Core\Infrastructure\Migrations\MigrationFactory;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
/** Internal runner: the caller must validate the target and own the outer transaction. */
final class VerifiedMigrationRunner
{
    public static function run(Connection $owner): array
    {
        if (!$owner->isTransactionActive()) { throw new \RuntimeException('Verified migration requires an outer transaction.'); }
        $before=$owner->fetchFirstColumn('SELECT version FROM migration.versions ORDER BY version');
        $factory=MigrationFactory::create($owner);MigrationFactory::prepareStorage($owner,$factory);
        $application=new Application('mikoucto verified migrations');$application->setAutoExit(false);$application->setCatchExceptions(false);
        $application->add(new MigrateCommand($factory));$output=new BufferedOutput();
        $input=new ArrayInput(['command'=>'migrations:migrate','--no-interaction'=>true,'--no-ansi'=>true]);$input->setInteractive(false);
        if ($application->run($input,$output)!==0) { throw new \RuntimeException('Verified migrations failed.'); }
        $after=$owner->fetchFirstColumn('SELECT version FROM migration.versions ORDER BY version');
        $expected=array_map(static fn($file)=>'App\\Migrations\\'.pathinfo($file,PATHINFO_FILENAME),glob(dirname(__DIR__,5).'/migrations/Version*.php'));
        sort($expected);if ($after!==$expected) { throw new \RuntimeException('Not all current migrations ran.'); }
        return ['before'=>$before,'after'=>$after,'applied'=>array_values(array_diff($after,$before)),'log'=>$output->fetch()];
    }
}
