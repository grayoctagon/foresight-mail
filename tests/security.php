<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/vendor/autoload.php';

use MailMirror\Auth\AuthRateLimiter;
use MailMirror\Exception\RateLimitException;
use MailMirror\Storage\AtomicFileWriter;
use MailMirror\Storage\JsonStore;
use MailMirror\Storage\PathResolver;
use MailMirror\Webmail\SettingsStore;
use MailMirror\Webmail\ExternalImageLoader;
use MailMirror\Webmail\HtmlSanitizer;
use MailMirror\Webmail\MimeReader;

$root = sys_get_temp_dir().'/foresight-security-'.bin2hex(random_bytes(6));
$writer = new AtomicFileWriter(); $json = new JsonStore($writer);
$paths = new PathResolver($root.'/mailstore', $root.'/secrets');
$passed = 0;
$check = static function(bool $ok, string $name) use (&$passed): void {
    if (!$ok) throw new RuntimeException('FAIL: '.$name);
    ++$passed; echo 'ok - '.$name."\n";
};
try {
    $now = 1000;
    $limiter = new AuthRateLimiter($paths,$json,$writer,static function() use (&$now): int { return $now; });
    for ($i=0;$i<5;$i++) $limiter->consume('login','203.0.113.1','Alice');
    try {$limiter->consume('login','203.0.113.1',' alice ');$blocked=false;} catch(RateLimitException $e){$blocked=$e->retryAfter===900;}
    $check($blocked,'Loginlimit normalisiert Benutzername und liefert Wartezeit');
    $limiter->consume('login','203.0.113.2','Alice');
    $check(true,'Andere IP hat ein eigenes Limit');
    $limiter->successfulLogin('203.0.113.1','ALICE');$limiter->consume('login','203.0.113.1','alice');
    $check(true,'Erfolgreicher Login setzt nur das Benutzer-/IP-Limit zurück');
    for ($i=0;$i<40;$i++) $limiter->consume('login','203.0.113.3','different-'.$i);
    try {$limiter->consume('login','203.0.113.3','another');$blocked=false;}catch(RateLimitException){$blocked=true;}
    $check($blocked,'Benutzerwechsel umgeht das IP-Gesamtlimit nicht');
    for ($i=0;$i<5;$i++) $limiter->consume('register','203.0.113.1','register-'.$i);
    try {$limiter->consume('register','203.0.113.1','sixth');$blocked=false;}catch(RateLimitException){$blocked=true;}
    $check($blocked,'Registrierungen haben ein separates IP-Limit');
    $disk=file_get_contents($root.'/mailstore/auth-limits/attempts.json');
    $check(!str_contains($disk,'Alice')&&!str_contains($disk,'203.0.113'),'Drosselspeicher enthält keine Klartext-Benutzernamen oder IPs');
    $now+=901;$limiter->consume('login','203.0.113.3','another');
    $check(count($json->read($root.'/mailstore/auth-limits/attempts.json'))===2,'Abgelaufene Limits werden beim nächsten Versuch entfernt');
    if (function_exists('pcntl_fork')) {
        $children=[];
        for($i=0;$i<12;$i++) {
            $pid=pcntl_fork();
            if($pid===-1)throw new RuntimeException('Fork fehlgeschlagen.');
            if($pid===0){try{$limiter->consume('login','203.0.113.4','parallel');exit(0);}catch(RateLimitException){exit(3);}}
            $children[]=$pid;
        }
        $accepted=0;$rejected=0;
        foreach($children as $pid){pcntl_waitpid($pid,$status);$exit=pcntl_wexitstatus($status);$accepted+=($exit===0);$rejected+=($exit===3);}
        $check($accepted===5&&$rejected===7,'Parallele Prozesse können das Loginlimit nicht überschreiten');
    }
    $full=[];for($i=0;$i<4096;$i++)$full['test-'.$i]=['count'=>1,'expires'=>$now+900];
    $json->write($root.'/mailstore/auth-limits/attempts.json',$full);
    try{$limiter->consume('login','203.0.113.5','new');$blocked=false;}catch(RateLimitException){$blocked=true;}
    $check($blocked&&count($json->read($root.'/mailstore/auth-limits/attempts.json'))===4096,'Drosselspeicher ist begrenzt und überschreitet seine Kapazität nicht');

    $settings=new SettingsStore($paths,$json);
    $legacy=['sidebarPercent'=>20,'listPercent'=>20,'density'=>'compact','pageSize'=>55,'columns'=>['message','bytes'],'columnWidths'=>['message'=>333],'favorites'=>[]];
    $json->write($paths->user('preview-user').'/webmail-settings.json',$legacy);
    $prefs=$settings->get('preview-user');
    $check($prefs['previewMode']==='text'&&$prefs['previewRules']===[],'Bestehende Benutzer erhalten Textstandard ohne Regeln');
    $prefs=$settings->save('preview-user',['previewRules'=>[['type'=>'domain','value'=>'@BÜCHER.AT','mode'=>'html']]]);
    $check($prefs['previewRules'][0]['value']==='xn--bcher-kva.at','IDN-Domains werden konsistent normalisiert');
    $check($prefs['columns']===['message','bytes']&&$prefs['columnWidths']['message']===333&&$prefs['density']==='compact','Vorschau-Änderungen erhalten Spalten, Breiten und Dichte');
    $check($settings->get('other-user')['previewRules']===[],'Vorschau-Regeln bleiben benutzerbezogen');
    $many=[];for($i=0;$i<201;$i++)$many[]=['type'=>'address','value'=>'user'.$i.'@example.at','mode'=>'text'];
    foreach([null, 'bad', ['wrong'=>'shape'], $many, [['type'=>'domain','value'=>'*.example.at','mode'=>'html']], [['type'=>'domain','value'=>'example.at/path','mode'=>'html']], [['type'=>'address','value'=>"michael@example.at\r\nX: bad",'mode'=>'text']], [['type'=>'domain','value'=>'example.at','mode'=>'script']]] as $bad) {
        try{$settings->save('preview-user',['previewRules'=>$bad]);$rejected=false;}catch(InvalidArgumentException){$rejected=true;}
        $check($rejected,'Ungültige Vorschau-Regel wird serverseitig abgewiesen');
    }

    foreach(['127.0.0.1','10.0.0.1','172.16.2.3','192.168.1.1','169.254.169.254','100.64.0.1','0.0.0.0','224.0.0.1','192.0.2.1','::1','fc00::1','fe80::1','::ffff:127.0.0.1','64:ff9b::7f00:1','2001:db8::1','2002:7f00:1::1','2001::1'] as $ip) $check(!ExternalImageLoader::publicAddress($ip),'Nichtöffentliches/Übergangs-Netzziel gesperrt: '.$ip);
    $check(ExternalImageLoader::publicAddress('8.8.8.8')&&ExternalImageLoader::publicAddress('2606:4700:4700::1111'),'Öffentliche IPv4-/IPv6-Adressen werden erkannt');
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aGAAAAABJRU5ErkJggg==');
    $calls=[];
    $transport=static function($host,$ip,$path,$timeout) use (&$calls,$png):array {$calls[]=[$host,$ip,$path];return ['status'=>200,'body'=>$png];};
    $public=static fn(string $host):array=>['8.8.8.8'];
    $loader=new ExternalImageLoader($public,$transport);
    $data=$loader->load('https://images.example.org/pixel?a=1');
    $check(str_starts_with($data??'','data:image/png;base64,')&&$calls[0]===['images.example.org','8.8.8.8','/pixel?a=1'],'Bildtransport verwendet exakt die geprüfte IP und überprüfte Rasterdaten');
    $loader->load('https://images.example.org/pixel?a=1');$check(count($calls)===1,'Doppelte Bild-URLs werden innerhalb des Abrufs nicht erneut geladen');
    foreach(['http://images.example.org/pixel','https://127.0.0.1/pixel','https://2130706433/pixel','https://user:password@images.example.org/pixel','https://images.example.org:8443/pixel','https://foo.local/pixel',"https://images.example.org/pixel\r\nX: evil"] as $url) $check((new ExternalImageLoader($public,$transport))->load($url)===null,'Unsichere Bild-URL abgewiesen');
    foreach([['127.0.0.1'],['8.8.8.8','10.0.0.1'],['::ffff:127.0.0.1'],[]] as $resolved) {
        $before=count($calls);$loader=new ExternalImageLoader(static fn()=> $resolved,$transport);
        $check($loader->load('https://images.example.org/pixel')===null&&count($calls)===$before,'DNS mit privatem/gemischtem Ziel wird vor Netzwerkzugriff blockiert');
    }
    foreach([['status'=>302,'body'=>$png],['status'=>200,'body'=>'<svg xmlns="http://www.w3.org/2000/svg"></svg>'],['status'=>200,'body'=>str_repeat('a',524289)]] as $response) {
        $loader=new ExternalImageLoader($public,static fn()=> $response);
        $check($loader->load('https://images.example.org/pixel')===null,'Weiterleitung, aktiver Inhalt oder übergroßes Bild wird nicht übernommen');
    }
    $loader=new ExternalImageLoader($public,$transport);
    for($i=0;$i<4;$i++)$loader->load('https://images.example.org/pixel?'.$i);
    $check($loader->load('https://images.example.org/pixel?5')===null,'Bildabrufe besitzen ein festes Mengenlimit');
    $sanitizer=new HtmlSanitizer(new ExternalImageLoader($public,$transport));
    $safe=$sanitizer->sanitize('<img src="https://images.example.org/pixel"><img src="https://127.0.0.1/private"><script>alert(1)</script>',[],true);
    $check(str_contains($safe,'data:image/png;base64,')&&!str_contains($safe,'images.example.org')&&!str_contains($safe,'127.0.0.1')&&!str_contains($safe,'<script'),'Auch nach Freigabe enthält Mail-HTML nur eingebettete Bilder und keine Remote-URLs');
    $check(str_contains($safe,"img-src data:;")&&!str_contains($safe,'img-src data: https:'),'CSP erlaubt auch bei externer Freigabe keine Browser-Netzwerkbilder');
    $check($sanitizer->externalStats()===['loaded'=>1,'blocked'=>1],'Blockierte und eingebettete Bilder werden nachvollziehbar gezählt');
    $calls=[];$sanitizer=new HtmlSanitizer(new ExternalImageLoader($public,$transport));
    $safe=$sanitizer->sanitize('<img src="https://images.example.org/pixel">');
    $check($calls===[]&&!str_contains($safe,'images.example.org'),'Ohne ausdrückliche Freigabe kein DNS-/Netzwerkabruf');
    $oversized='data:image/png;base64,'.str_repeat('A',700000);
    $safe=(new HtmlSanitizer())->sanitize(str_repeat('<img src="cid:pixel">',20),['pixel'=>$oversized]);
    $check(strlen($safe)<4300000,'Wiederholte CID-Bilder können die Einbettungsgrenze nicht vervielfachen');
    $eml=$root.'/only-html.eml';
    $writer->write($eml,"From: Michael <michael@example.at>\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n<p>Hallo &amp; willkommen</p><script>evil()</script><p>Zweite Zeile</p>");
    $body=(new MimeReader($json,new HtmlSanitizer()))->body($eml);
    $check($body['textDerivedFromHtml']&&str_contains($body['text'],"Hallo & willkommen\n")&&!str_contains($body['text'],'evil()')&&!str_contains($body['text'],'font:14px'),'HTML-only-Mails erhalten eine sichere lesbare Textfassung');
    echo "\n{$passed} Sicherheits-/Vorschau-Prüfungen bestanden.\n";
} finally {
    $delete=static function(string $path) use (&$delete):void {
        if(is_dir($path)&&!is_link($path)){foreach(array_diff(scandir($path),['.','..']) as $name)$delete($path.'/'.$name);rmdir($path);}else if(file_exists($path)||is_link($path))unlink($path);
    };
    $delete($root);
}
