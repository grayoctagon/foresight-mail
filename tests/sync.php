<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/FakeImap.php';

use MailMirror\Config\AccountConfigValidator;
use MailMirror\Exception\ConfigurationException;
use MailMirror\Exception\NotFoundException;
use MailMirror\Imap\DeadlineImapStream;
use MailMirror\Imap\ImapAdapterFactory;
use MailMirror\Storage\AccountLock;
use MailMirror\Storage\AtomicFileWriter;
use MailMirror\Storage\JsonStore;
use MailMirror\Sync\SyncEngine;
use MailMirror\Tests\FakeImapAdapter;
use MailMirror\Tests\FakeImapState;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionTimedOutException;

$root = sys_get_temp_dir().'/foresight-sync-'.bin2hex(random_bytes(6));
$writer = new AtomicFileWriter();
$json = new JsonStore($writer);
$json->write($root.'/deployment.json', ['schema_version'=>1,'app'=>['session_name'=>'sync_test','allow_registration'=>true],
    'paths'=>['mailstore_root'=>$root.'/mailstore','secret_store_root'=>$root.'/secrets']]);
putenv('FORESIGHT_DEPLOYMENT_FILE='.$root.'/deployment.json');
$app = require dirname(__DIR__).'/bootstrap.php';
$passed = 0;
$assert = static function (bool $ok, string $message) use (&$passed): void {
    if (!$ok) throw new RuntimeException('FAIL: '.$message);
    ++$passed; echo 'ok - '.$message."\n";
};
$now = time();
$fake = new FakeImapState();
$fake->mailboxes = ['INBOX'=>['uidvalidity'=>1,'messages'=>[
    1=>['raw'=>"From: sender@example.test\r\nSubject: One\r\n\r\nBody\r\n",'flags'=>[]],
]]];
$configs = [];
$factory = new ImapAdapterFactory($app['credentials'], static function ($config) use ($fake, &$configs) {
    $configs[] = $config;
    return new FakeImapAdapter($fake);
});
$sync = new SyncEngine($app['accounts'],$app['jobs'],$factory,$app['catalog'],$app['folders'],$app['objects'],
    $app['emailIds'],$app['paths'],$app['json'],$app['logger'],static function () use (&$now) { return $now; });
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (new FilesystemIterator($path) as $file) $remove($file->getPathname());
        rmdir($path);
    } elseif (file_exists($path) || is_link($path)) unlink($path);
};

try {
    $alice=$app['auth']->register('alice','fixture-password-1234')->userId;
    $bob=$app['auth']->register('bob','fixture-password-1234')->userId;
    $config=['label'=>'Scheduled','imap'=>['hostname'=>'fake.test','port'=>993,'connection_security'=>'implicit_tls',
        'authentication'=>['username'=>'alice','credential_ref'=>'imap-main']]];
    $account=$app['accounts']->create($alice,$config);$id=$account['account_id'];
    $assert($account['sync']['interval_minutes']===0,'Neue Postfächer sind standardmäßig manuell');
    $legacy=$account;unset($legacy['sync']);
    $app['json']->write($app['paths']->account($alice,$id).'/config.json',$legacy);
    $assert($app['accounts']->get($alice,$id)['sync']['interval_minutes']===0 && $app['accounts']->list($alice)[0]['sync']['interval_minutes']===0,'Alte Konfigurationen bleiben kompatibel und manuell');
    $r=$sync->check($alice,$id);
    $assert($r['reason']==='manual' && $r['retryAfterSeconds']===300 && $r['job']===null && $configs===[],'Manuelle Prüfung startet weder Job noch IMAP');
    foreach (AccountConfigValidator::SYNC_INTERVALS as $interval) {
        $saved=$app['accounts']->update($alice,$id,['sync'=>['interval_minutes'=>$interval]]);
        $assert($saved['sync']['interval_minutes']===$interval,'Intervall speichern: '.$interval);
    }
    foreach ([-1,1,14,45,90,1441,'15',15.0,true,[],['x']] as $value) {
        try { $app['accounts']->update($alice,$id,['sync'=>['interval_minutes'=>$value]]);$assert(false,'Ungültiges Intervall abweisen'); }
        catch(ConfigurationException) { $assert(true,'Ungültiges Intervall abgewiesen'); }
    }
    $assert($app['accounts']->get($alice,$id)['sync']['interval_minutes']===1440,'Fehlerhafte Updates verändern Konfiguration nicht');
    try { $sync->check($bob,$id);$assert(false,'Fremdes Postfach'); }
    catch(NotFoundException) { $assert(true,'Automatische Prüfung verweigert fremdes Postfach'); }
    $app['accounts']->update($alice,$id,['sync'=>['interval_minutes'=>15],'enabled'=>false]);
    $assert($sync->check($alice,$id)['reason']==='disabled' && $configs===[],'Deaktivierte Postfächer werden nicht synchronisiert');
    $app['accounts']->update($alice,$id,['enabled'=>true]);

    $lock=new AccountLock();$lock->acquire($app['paths']->account($alice,$id).'/locks/sync.lock');
    try {
        $r=$sync->check($alice,$id);
        $assert($r['reason']==='busy' && $r['retryAfterSeconds']===60 && $r['job']===null && $configs===[],'Besetztes Postfach ohne Job antwortet sofort mit busy');
        try {$sync->start($alice,$id);$assert(false,'Start ohne Job unter Sperre');}
        catch(\MailMirror\Exception\BusyException) {$assert($app['jobs']->status($alice,$id)===null,'Auch Job-Erstellung ist durch flock geschützt');}
    } finally {$lock->release();}

    $r=$sync->check($alice,$id);$job=$r['job'];
    $assert($r['reason']==='running' && $r['retryAfterSeconds']===60 && $job['trigger']==='automatic' && $job['phase']==='index_mailboxes','Fälliger erster Lauf erledigt genau einen Schritt und meldet 60 Sekunden');
    $assert($configs[0]['imap']['timeout_seconds']===5 && $configs[0]['performance']['max_request_runtime_seconds']===15 && isset($configs[0]['imap']['_request_deadline']),'Automatische Schritte begrenzen Socket- und Arbeitszeit');
    $assert($app['accounts']->get($alice,$id)['imap']['timeout_seconds']===30,'Automatische Grenzen verändern keine gespeicherten Performance-Einstellungen');
    $before=count($configs);$r=$sync->check($alice,$id);
    $assert($r['job']['job_id']===$job['job_id'] && count($configs)===$before,'Zweiter Tab überspringt denselben reservierten Arbeitsschritt');
    $lock->acquire($app['paths']->account($alice,$id).'/locks/sync.lock');
    try {
        $beforeJob=$app['jobs']->active($alice,$id);
        $beforeConfigs=count($configs);
        $sync->status($alice,$id);$sync->status($alice,$id);
        $assert(count($configs)===$beforeConfigs && $app['jobs']->active($alice,$id)===$beforeJob,'Häufige Statusabfragen lesen ohne IMAP oder Job-Schreibzugriff, auch bei gehaltener Sync-Sperre');
        $assert(($sync->start($alice,$id)['busy']??false) && ($sync->advance($alice,$id)['busy']??false),'Manueller Start und Advance beachten dieselbe Kontosperre');
        $assert($sync->check($alice,$id)['reason']==='busy' && $app['jobs']->active($alice,$id)===$beforeJob,'Kein zweiter Prozess verändert Jobzustand unter Sperre');
    } finally {$lock->release();}
    for($i=0;$i<30 && $r['job']['state']!=='completed';$i++) {$now+=60;$r=$sync->check($alice,$id);}
    $assert($r['job']['state']==='completed' && $r['retryAfterSeconds']===300 && !$r['syncing'],'Großer Gesamtjob endet über mehrere kurze Aufrufe ohne Dauerrequest');
    $assert($fake->rawFetches===1,'Automatischer Sync materialisiert reale lokale EML genau einmal');
    $completed=$r['job'];$now=strtotime($completed['completed_at'])+899;
    $assert($sync->check($alice,$id)['reason']==='not_due','Intervall beginnt beim Abschluss, nicht beim Öffnen eines Tabs');
    $now++;$r=$sync->check($alice,$id);
    $assert($r['job']['job_id']!==$completed['job_id'] && $r['syncing'],'Nach 15 Minuten ist ein neuer Lauf fällig');
    $manual=$sync->start($alice,$id);$now+=120;$before=count($configs);
    $assert($manual['trigger']==='manual' && $sync->check($alice,$id)['reason']==='manual_job' && count($configs)===$before,'Expliziter manueller Start übernimmt Automatik; Polling setzt pausierte manuelle Jobs nicht fort');
    $app['accounts']->update($alice,$id,['sync'=>['interval_minutes'=>0]]);
    $assert($sync->check($alice,$id)['reason']==='manual','Nur manuell stoppt weitere automatische Arbeitsschritte');

    // A nonretryable configuration failure must not restart on every poll.
    $broken=$app['accounts']->create($alice,$config+['sync'=>['interval_minutes'=>15]]);$brokenId=$broken['account_id'];
    $failingFactory=new ImapAdapterFactory($app['credentials'],static fn()=>throw new ConfigurationException('Test-Konfiguration fehlt.'));
    $failing=new SyncEngine($app['accounts'],$app['jobs'],$failingFactory,$app['catalog'],$app['folders'],$app['objects'],
        $app['emailIds'],$app['paths'],$app['json'],$app['logger'],static function () use (&$now) {return $now;});
    $r=$failing->check($alice,$brokenId);
    $assert($r['reason']==='failed' && $r['job']['last_error']['retryable']===false && $r['retryAfterSeconds']===300,'Permanenter Sync-Fehler wird gespeichert und sichtbar zurückgegeben');
    $now=strtotime($r['job']['updated_at'])+300;
    $assert($failing->check($alice,$brokenId)['reason']==='not_due','Fehlgeschlagener Lauf wartet ebenfalls das eingestellte Intervall');

    // Genuine concurrent PHP processes share one job, not one singleton per session.
    if(function_exists('pcntl_fork')) {
        $concurrent=$app['accounts']->create($alice,$config);$concurrentId=$concurrent['account_id'];$children=[];
        for($i=0;$i<8;$i++) {
            $pid=pcntl_fork();
            if($pid===0) {
                try {$sync->start($alice,$concurrentId);exit(0);}catch(\MailMirror\Exception\BusyException){exit(0);}catch(Throwable){exit(1);}
            }
            if($pid<0)throw new RuntimeException('Fork fehlgeschlagen.');
            $children[]=$pid;
        }
        foreach($children as $pid) {pcntl_waitpid($pid,$status);$assert(pcntl_wexitstatus($status)===0,'Paralleler Starter antwortet kontrolliert');}
        $assert(count(glob($app['paths']->account($alice,$concurrentId).'/sync/jobs/*.json'))===1,'Acht parallele Starts erzeugen genau einen Job');

        $autoAccount=$app['accounts']->create($alice,$config+['sync'=>['interval_minutes'=>15]]);$autoId=$autoAccount['account_id'];$children=[];
        for($i=0;$i<8;$i++) {
            $pid=pcntl_fork();
            if($pid===0) {try {$sync->check($alice,$autoId);exit(0);}catch(Throwable){exit(1);}}
            if($pid<0)throw new RuntimeException('Fork fehlgeschlagen.');
            $children[]=$pid;
        }
        foreach($children as $pid){pcntl_waitpid($pid,$status);$assert(pcntl_wexitstatus($status)===0,'Paralleler automatischer Prüfer antwortet kontrolliert');}
        $autoJob=$app['jobs']->status($alice,$autoId);
        $assert(count(glob($app['paths']->account($alice,$autoId).'/sync/jobs/*.json'))===1 && $autoJob['phase']==='index_mailboxes' && $autoJob['index_processed']===0,'Acht parallele automatische Prüfungen erzeugen nur einen Job und einen Arbeitsschritt');

        $server=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$address=stream_socket_get_name($server,false);
        $pid=pcntl_fork();
        if($pid===0) {
            $client=stream_socket_accept($server,2);
            if($client){for($i=0;$i<40;$i++){if(@fwrite($client,'x')===false)break;usleep(15000);}fclose($client);}
            fclose($server);exit(0);
        }
        if($pid<0)throw new RuntimeException('Fork fehlgeschlagen.');
        $stream=new DeadlineImapStream(microtime(true)+.15);$started=microtime(true);
        try {
            $stream->open('tcp','127.0.0.1',(int)substr(strrchr($address,':'),1),5);
            $stream->fgets();$assert(false,'Trickle-Deadline');
        } catch(ImapConnectionTimedOutException) {
            $assert(microtime(true)-$started<1,'Tröpfelnder Server kann die absolute I/O-Deadline nicht verlängern');
        } finally {$stream->close();fclose($server);pcntl_waitpid($pid,$status);}
    }
    echo "\n{$passed} Sync-Prüfungen erfolgreich.\n";
} finally {
    $remove($root);
}
