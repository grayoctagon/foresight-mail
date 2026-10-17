<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/vendor/autoload.php';

use MailMirror\Config\DeploymentConfig;
use MailMirror\Exception\ConfigurationException;
use MailMirror\Http\PublicPath;
use MailMirror\Storage\AtomicFileWriter;
use MailMirror\Storage\JsonStore;

$root = sys_get_temp_dir().'/foresight-deployment-'.bin2hex(random_bytes(6));
$writer = new AtomicFileWriter();
$json = new JsonStore($writer);
$project = $root.'/app';
$writer->ensureDirectory($project.'/public');
$passed = 0;
$assert = static function(bool $value, string $label) use (&$passed): void {
    if (!$value) throw new RuntimeException('FAIL: '.$label);
    ++$passed; echo 'ok - '.$label."\n";
};
$originalOverride = getenv('FORESIGHT_DEPLOYMENT_FILE');
try {
    $config = ['schema_version'=>1,'app'=>['session_name'=>'deployment_test'],
        'paths'=>['mailstore_root'=>'../data/mailstore','secret_store_root'=>'../data/secrets']];
    $deployment = new DeploymentConfig($config,$project);
    $assert($deployment->mailstoreRoot()===$root.'/data/mailstore','Relativer Mailstore wird am Projekt verankert');
    $assert($deployment->secretStoreRoot()===$root.'/data/secrets','Relative Secrets bleiben außerhalb des Projekts');
    $assert($deployment->basePath()==='','CLI benötigt keinen URL-Präfix');
    $absolute=$config;
    $absolute['paths']=['mailstore_root'=>$root.'/data/mailstore','secret_store_root'=>$root.'/data/secrets'];
    $assert((new DeploymentConfig($absolute,$project))->mailstoreRoot()===$deployment->mailstoreRoot(),'Absolute Bestandskonfiguration bleibt kompatibel');
    $json->write($project.'/config/test.json',$config);
    putenv('FORESIGHT_DEPLOYMENT_FILE=config/test.json');
    $assert(DeploymentConfig::load($project)->secretStoreRoot()===$root.'/data/secrets','Relativer Konfigurations-Override ist unabhängig vom Arbeitsverzeichnis');
    symlink($project.'/public',$root.'/public-link');
    foreach(['public/data','../app/public/secrets','../public-link/mailstore',"../bad\0path",'php://memory'] as $path) {
        $bad=$config;$bad['paths']['mailstore_root']=$path;
        try {new DeploymentConfig($bad,$project);$rejected=false;} catch(ConfigurationException){$rejected=true;}
        $assert($rejected,'Private Daten in öffentlichen/ungültigen Pfaden werden verweigert');
    }
    foreach([
        '/index.php'=>'', '/api.php'=>'', '/api.php/webmail/messages'=>'',
        '/mail/index.php'=>'/mail', '/mail/api.php'=>'/mail', '/mail/api.php/webmail/messages'=>'/mail',
        '/foresight-mail/index.php'=>'/foresight-mail', '/nested/other/api.php/session'=>'/nested/other',
    ] as $script=>$expected) {
        $assert(PublicPath::detect(['SCRIPT_NAME'=>$script,'SCRIPT_FILENAME'=>'/different/private/path/public/api.php'])===$expected,'URL-Pfad folgt öffentlichem Mount: '.$script);
    }
    $assert(PublicPath::detect(['SCRIPT_NAME'=>'/mail/api.php/route/api.php/part','PATH_INFO'=>'/route/api.php/part'])==='/mail','API-Dateiname innerhalb der Route ändert den Mount nicht');
    $assert(PublicPath::detect(['SCRIPT_NAME'=>'/mail/api.php','PATH_INFO'=>'/route/api.php/part'])==='/mail','Separates PATH_INFO wird nicht doppelt entfernt');
    foreach(['https://other.example/index.php',"/mail\n/index.php",'/mail\\index.php','/mail/index.php?bad=1'] as $script) {
        try {PublicPath::detect(['SCRIPT_NAME'=>$script]);$rejected=false;} catch(InvalidArgumentException){$rejected=true;}
        $assert($rejected,'Ungültiger öffentlicher Einstiegspfad wird verweigert');
    }
    $assert(PublicPath::resolve('/old','../foresight')==='/foresight','Lokale Legacy-Weiterleitung ist relativ');
    $assert(PublicPath::resolve('/nested/old','../foresight')==='/nested/foresight','Legacy-Weiterleitung unterstützt Unterordner');
    echo "\n{$passed} Deployment-Prüfungen bestanden.\n";
} finally {
    putenv($originalOverride===false ? 'FORESIGHT_DEPLOYMENT_FILE' : 'FORESIGHT_DEPLOYMENT_FILE='.$originalOverride);
    $delete=static function(string $path) use (&$delete):void {
        if(is_dir($path)&&!is_link($path)){foreach(array_diff(scandir($path),['.','..']) as $name)$delete($path.'/'.$name);rmdir($path);}
        else if(file_exists($path)||is_link($path))unlink($path);
    };
    $delete($root);
}
