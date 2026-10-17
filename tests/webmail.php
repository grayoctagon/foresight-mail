<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/vendor/autoload.php';

use MailMirror\Storage\AtomicFileWriter;
use MailMirror\Storage\JsonStore;
use MailMirror\Webmail\HtmlSanitizer;
use MailMirror\Webmail\MimeReader;
use MailMirror\Webmail\LocalMailbox;

$root = sys_get_temp_dir().'/foresight-webmail-'.bin2hex(random_bytes(6));
$mount = '';
foreach ($argv as $argument) if (str_starts_with($argument, '--mount=')) $mount = rtrim(substr($argument, 8), '/');
if ($mount !== '' && !preg_match('~^(?:/[A-Za-z0-9_-]+)+$~D', $mount)) throw new InvalidArgumentException('Ungültiger Test-Unterordner.');
$writer = new AtomicFileWriter();
$json = new JsonStore($writer);
$json->write($root.'/deployment.json', [
    'schema_version' => 1, 'app' => ['session_name' => 'webmail_test', 'allow_registration' => true],
    'paths' => ['mailstore_root' => $root.'/mailstore', 'secret_store_root' => $root.'/secrets'],
]);
putenv('FORESIGHT_DEPLOYMENT_FILE='.$root.'/deployment.json');
$app = require dirname(__DIR__).'/bootstrap.php';
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
$address = stream_socket_get_name($socket, false);
fclose($socket);
$webroot = $root.'/www';
$writer->ensureDirectory($webroot);
$public = dirname(__DIR__).'/public';
foreach (['index.php','api.php','_bootstrap.php','assets'] as $entry) {
    if (!symlink($public.'/'.$entry, $webroot.'/'.$entry)) throw new RuntimeException('Test-Webroot kann nicht erstellt werden.');
}
if ($mount !== '') {
    $writer->ensureDirectory(dirname($webroot.$mount));
    if (!symlink($public, $webroot.$mount)) throw new RuntimeException('Test-Mount kann nicht erstellt werden.');
}
$process = proc_open([PHP_BINARY, '-S', $address, '-t', $webroot], [0 => ['pipe','r'], 1 => ['file',$root.'/http.log','a'], 2 => ['file',$root.'/http.log','a']], $pipes);
$passed = 0;
$assert = static function (bool $value, string $label) use (&$passed): void {
    if (!$value) throw new RuntimeException('FAIL: '.$label);
    ++$passed; echo 'ok - '.$label."\n";
};
$clients = ['alice' => ['cookie'=>'','csrf'=>''], 'bob' => ['cookie'=>'','csrf'=>''], 'anon' => ['cookie'=>'','csrf'=>'']];
$request = static function (string $client, string $path, string $method = 'GET', mixed $body = null, bool $csrf = true) use ($address, $mount, &$clients): array {
    $headers = ['Accept: application/json','Cookie: '.$clients[$client]['cookie']];
    if ($csrf) $headers[] = 'X-CSRF-Token: '.$clients[$client]['csrf'];
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    $context = stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),
        'content'=>$body === null ? '' : (is_string($body) ? $body : json_encode($body)), 'ignore_errors'=>true, 'timeout'=>15]]);
    $raw = file_get_contents('http://'.$address.$mount.'/api.php'.$path, false, $context);
    $status = 0;
    foreach ($http_response_header as $h) {
        if (preg_match('~^HTTP/\S+ (\d+)~',$h,$m)) $status=(int)$m[1];
        if (preg_match('/^Set-Cookie:\s*([^;]+)/i',$h,$m)) $clients[$client]['cookie']=$m[1];
    }
    $decoded = json_decode((string)$raw,true);
    if (isset($decoded['data']['csrf_token'])) $clients[$client]['csrf']=$decoded['data']['csrf_token'];
    return ['status'=>$status,'json'=>$decoded,'data'=>$decoded['data']??null,'raw'=>$raw,'headers'=>$http_response_header];
};
$fixture = static function (string $user,string $account) use ($app): array {
    $folders = $app['catalog']->reconcile($user,$account,[
        ['remote_name'=>'INBOX','attributes'=>['\\Inbox'],'selectable'=>true],
        ['remote_name'=>'Archive','attributes'=>['\\Archive'],'selectable'=>true],
        ['remote_name'=>'Large','attributes'=>[],'selectable'=>true],
        ['remote_name'=>'Sizes','attributes'=>[],'selectable'=>true],
    ]);
    $map = array_column($folders,'local_folder_id','remote_name');
    $raw = "From: =?UTF-8?Q?J=C3=B6rg_Beispiel?= <joerg@example.test>\r\nTo: Reader <reader@example.test>\r\nCc: Team <team@example.test>\r\nSubject: =?UTF-8?Q?Pr=C3=BCfung_=E2=80=93_HTML_und_Anhang?=\r\nDate: Sun, 27 Sep 2026 19:00:00 +0200\r\nMessage-ID: <webmail-fixture@example.test>\r\nX-Priority: 1\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"outer\"\r\n\r\n".
        "--outer\r\nContent-Type: multipart/alternative; boundary=\"inner\"\r\n\r\n".
        "--inner\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode("Guten Tag!\nDiese Nachricht prüft Unicode, Plaintext und Anhänge.")."\r\n".
        "--inner\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n<p style=\"color:blue\">Guten <b>Tag</b>!</p><script>window.parent.pwned=true</script><img src=\"https://tracking.example.test/pixel\" onerror=\"alert(1)\"><img src=\"cid:pixel\"><a href=\"javascript:alert(1)\">Bad</a><a href=\"https://example.test\">Link</a><iframe src=\"/api.php/logout\"></iframe>\r\n--inner--\r\n".
        "--outer\r\nContent-Type: application/octet-stream\r\nContent-Disposition: attachment; filename=\"../../report.txt\"\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode("attachment\0bytes\r\n")."\r\n".
        "--outer\r\nContent-Type: image/png\r\nContent-ID: <pixel>\r\nContent-Disposition: inline; filename=\"pixel.png\"\r\nContent-Transfer-Encoding: base64\r\n\r\niVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aGAAAAABJRU5ErkJggg==\r\n--outer--\r\n";
    $object = $app['objects']->materialize($user,$account,$raw);
    $locations = [];
    for($uid=1;$uid<=237;$uid++) $locations[$uid]=['uid'=>$uid,'hash'=>$object['hash'],'content_state'=>'materialized',
        'flags'=>$uid%2 ? [] : ['\\Seen','\\Flagged'], 'internal_date'=>date(DATE_ATOM,1780000000+$uid*60)];
    $locations[238]=['uid'=>238,'hash'=>null,'content_state'=>'pending','flags'=>[],'internal_date'=>date(DATE_ATOM,1780000000+238*60)];
    $app['folders']->commit($user,$account,$map['INBOX'],['uidvalidity'=>7],$locations,'test');
    $app['folders']->commit($user,$account,$map['Archive'],['uidvalidity'=>9],[1=>$locations[2]],'test');
    $large=[];
    for($uid=1;$uid<=50000;$uid++) $large[$uid]=['uid'=>$uid,'hash'=>null,'content_state'=>'pending','flags'=>[],'internal_date'=>'2026-09-01T10:00:00+00:00'];
    $app['folders']->commit($user,$account,$map['Large'],['uidvalidity'=>1],$large,'test');
    $sizes=[];
    foreach([1258291,512,1073741824,1372,10240] as $i=>$bytes) $sizes[$i+1]=['uid'=>$i+1,'hash'=>null,'content_state'=>'pending','flags'=>[],'rfc822_size'=>$bytes,'internal_date'=>'2026-09-01T10:00:00+00:00'];
    $app['folders']->commit($user,$account,$map['Sizes'],['uidvalidity'=>1],$sizes,'test');
    return [$map,$raw,$object];
};
$waitForPage = static function (string $scope, array $extra=[]) use ($request): array {
    for($i=0;$i<500;$i++) {
        $r=$request('alice','/webmail/messages?'.http_build_query(['folders'=>$scope,'limit'=>100]+$extra));
        if($r['status']!==200 || empty($r['data']['indexing'])) return $r;
    }
    throw new RuntimeException('Index nicht fertig.');
};

try {
    for($i=0;$i<100;$i++){ $probe=@stream_socket_client('tcp://'.$address,$errno,$error,.1);if($probe){fclose($probe);break;}usleep(20000); }
    $page=file_get_contents('http://'.$address.$mount.'/');
    $assert(str_contains($page,'data-base-path="'.$mount.'"') && str_contains($page,'href="./assets/css/webmail.css') && str_contains($page,'src="./assets/js/webmail.js'),'Automatischer URL-Basispfad und relative Assets: '.($mount?:'/'));
    foreach(['src/Config/DeploymentConfig.php','config/deployment.php','composer.lock','.git/config','README.md'] as $private) {
        $context=stream_context_create(['http'=>['ignore_errors'=>true]]);
        file_get_contents('http://'.$address.$mount.'/'.$private,false,$context);
        $assert(str_contains($http_response_header[0],'404'),'Private Ressource nicht öffentlich: '.$private);
    }
    $r=$request('anon','/accounts');$assert($r['status']===401 && $r['json']['ok']===false,'Unauthentifizierter API-Zugriff wird verweigert');
    $assert((bool)preg_match('~Set-Cookie:.*; path='.preg_quote($mount.'/','~').'(?:;|$)~i',implode("\n",$r['headers'])),'Session-Cookie auf öffentlichen Unterordner begrenzt');
    $r=$request('anon','?route=/session');$assert($r['status']===200 && $r['data']['authenticated']===false,'API-Query-Routing ohne PATH_INFO funktioniert');
    foreach(['alice','bob'] as $name) {
        $r=$request($name,'/register','POST',['username'=>$name,'password'=>'fixture-password-1234']);
        $assert($r['status']===201 && $r['json']['ok']===true,'Registrierung '.$name);
        $users[$name]=$r['data']['user']['user_id'];
    }
    $r=$request('alice','/session');$assert($r['data']['authenticated']===true && isset($r['data']['csrf_token']),'Session und CSRF-Token');
    $assert(str_contains(implode("\n",$r['headers']),'Cache-Control: no-store'),'Session ist nicht cachebar');
    $config=['label'=>'Fixture mailbox','enabled'=>true,'imap'=>['hostname'=>'invalid.test','port'=>993,'connection_security'=>'implicit_tls','authentication'=>['username'=>'reader','method'=>'auto','credential_ref'=>'imap-main']],
        'identities'=>[],'smtp'=>null,'imap_password'=>'never-return-this-secret'];
    $r=$request('alice','/accounts','POST',$config,false);$assert($r['status']===401,'CSRF auf Account-Erstellung');
    $r=$request('alice','/accounts','POST',$config);$assert($r['status']===201,'Account über bestehende API anlegen');
    $account=$r['data']['account']['account_id'];
    $assert(!str_contains($r['raw'],'never-return-this-secret'),'Credential nicht in API-Antwort');
    $r=$request('alice','/accounts');$assert(count($r['data']['accounts'])===1,'Account-Liste des angemeldeten Benutzers');
    $r=$request('bob','/accounts');$assert($r['data']['accounts']===[],'Fremde Accounts erscheinen nicht in der Liste');
    $r=$request('alice','/accounts/'.$account);$assert($r['data']['account']['account_id']===$account,'Eigenen Account lesen');
    $r=$request('bob','/accounts/'.$account);$assert($r['status']===404,'Fremden Account verweigern');
    [$map,$raw,$object]=$fixture($users['alice'],$account);
    $scope=$account.'~'.$map['INBOX'];
    $messageId=$map['INBOX'].'~7~1';
    $messageRoute='/webmail/accounts/'.$account.'/messages/'.$messageId;
    $r=$request('alice','/webmail/accounts/'.$account.'/folders');
    $inbox=array_values(array_filter($r['data']['folders'],fn($f)=>$f['name']==='INBOX'))[0];
    $assert($inbox['counts']===['total'=>238,'unread'=>120,'pending'=>1],'Ordner mit realen lokalen Zählern');
    $r=$waitForPage($scope);
    $assert($r['status']===200 && count($r['data']['messages'])===100 && $r['data']['total']===238,'Pagination liefert genau eine Summary-Seite');
    $assert(!isset($r['data']['messages'][0]['body']),'Liste enthält keinen Body');
    $assert($r['data']['messages'][0]['uid']===238 && $r['data']['messages'][0]['contentState']==='pending','Partiell vorhandene Nachrichten werden explizit angezeigt');
    $cursor=$r['data']['nextCursor'];$seen=array_column($r['data']['messages'],'id');
    while($cursor) {
        $r=$request('alice','/webmail/messages?'.http_build_query(['folders'=>$scope,'limit'=>100,'cursor'=>$cursor]));
        $assert($r['status']===200,'Cursor-Seite laden');$seen=array_merge($seen,array_column($r['data']['messages'],'id'));$cursor=$r['data']['nextCursor'];
    }
    $assert(count($seen)===238 && count(array_unique($seen))===238,'Pagination ohne Lücken oder Duplikate');
    $r=$waitForPage($scope);
    $oldCursor=$r['data']['nextCursor'];
    $folderState=$app['folders']->state($users['alice'],$account,$map['INBOX']);
    $app['json']->write($app['paths']->folder($users['alice'],$account,$map['INBOX']).'/state.json',array_replace($folderState,['uidvalidity'=>8]));
    $r=$waitForPage($scope,['cursor'=>$oldCursor]);
    $assert($r['status']===409,'Geänderte Indexgeneration invalidiert alte Cursor');
    $r=$request('alice',$messageRoute);
    $assert($r['status']===404,'UIDVALIDITY-Wechsel verweigert veraltete Nachrichten-ID');
    $app['json']->write($app['paths']->folder($users['alice'],$account,$map['INBOX']).'/state.json',$folderState);
    $pendingPath=$app['paths']->folder($users['alice'],$account,$map['INBOX']).'/commit.pending.json';
    $app['json']->write($pendingPath,['schema_version'=>1,'state'=>$folderState,'locations'=>$app['folders']->locations($users['alice'],$account,$map['INBOX'])]);
    $syncLock=new \MailMirror\Storage\AccountLock();
    $syncLock->acquire($app['paths']->account($users['alice'],$account).'/locks/sync.lock');
    try {
        $r=$request('alice',$messageRoute);
        $assert($r['status']===409,'Laufender Ordner-Commit liefert keine gemischte Nachricht');
    } finally {$syncLock->release();}
    $r=$request('alice',$messageRoute);
    $assert($r['status']===200 && !is_file($pendingPath),'Abgebrochener lokaler Commit wird ohne IMAP wiederhergestellt');
    $r=$waitForPage($scope,['q'=>'Jörg','unread'=>'1','sort'=>'subject','direction'=>'asc']);
    $assert($r['data']['total']===119,'Unicode-Suche und Filter vor Pagination');
    $r=$waitForPage($scope.','.$account.'~'.$map['Archive']);
    $assert($r['data']['total']===239,'Ordnerübergreifende Ansicht');
    $sizeScope=$account.'~'.$map['Sizes'];
    $waitForPage($sizeScope);
    foreach(['asc'=>[512,1372,10240,1258291,1073741824],'desc'=>[1073741824,1258291,10240,1372,512]] as $direction=>$expected) {
        $cursor=null;$actual=[];
        do {
            $params=['folders'=>$sizeScope,'limit'=>2,'sort'=>'bytes','direction'=>$direction];
            if($cursor)$params['cursor']=$cursor;
            $r=$request('alice','/webmail/messages?'.http_build_query($params));
            if($r['status']!==200) throw new RuntimeException('Größensortierung fehlgeschlagen');
            $actual=array_merge($actual,array_column($r['data']['messages'],'bytes'));
            $cursor=$r['data']['nextCursor'];
        } while($cursor);
        $assert($actual===$expected,'Numerische Byte-Sortierung über Cursor-Seiten: '.$direction);
    }
    $r=$request('alice',$messageRoute);
    $assert($r['status']===200 && $r['data']['message']['from']['name']==='Jörg Beispiel','Einzelne Nachricht mit dekodiertem Absender');
    $assert($r['data']['message']['to'][0]['email']==='reader@example.test' && $r['data']['message']['cc'][0]['email']==='team@example.test' && $r['data']['message']['bytes']===strlen($raw),'Reale An-/Cc-Adressen und exakte EML-Größe');
    $assert(count($r['data']['message']['attachments'])===2,'Attachments und Inline-Ressource werden erkannt');
    $assert(!str_contains($r['data']['message']['attachments'][0]['name'],'/'),'Anhang-Dateiname ist bereinigt');
    $r=$request('alice',$messageRoute.'/body');
    $assert(str_contains($r['data']['text'],'Anhänge'),'Plaintext-MIME dekodiert');
    $html=$r['data']['html'];
    $assert(!str_contains($html,'<script') && !str_contains($html,'onerror') && !str_contains($html,'javascript:') && !str_contains($html,'<iframe'),'HTML-Sanitization entfernt aktiven Inhalt');
    $assert(!str_contains($html,'tracking.example.test'),'Externe Bilder standardmäßig blockiert');
    $assert(str_contains($html,'data:image/png;base64,'),'CID-Bild als verifizierte Rastergrafik eingebettet');
    $assert(str_contains($html,'Content-Security-Policy'),'Zusätzliche CSP im Maildokument');
    $r=$request('alice',$messageRoute.'/body?external=1');$assert(str_contains($r['data']['html'],'tracking.example.test'),'Externe Bilder nur nach ausdrücklichem Abruf');
    $r=$request('alice',$messageRoute.'/attachments/a0');
    $assert($r['raw']==="attachment\0bytes\r\n",'Anhang wird bytegetreu dekodiert');
    $assert(str_contains(strtolower(implode("\n",$r['headers'])),'content-type: application/octet-stream'),'Anhänge ausschließlich als Download mit sicherem MIME-Typ');
    $r=$request('alice',$messageRoute.'/raw');$assert($r['raw']===$raw,'EML-Download ist bytegetreu');
    foreach(['/accounts/'.$account, '/webmail/accounts/'.$account.'/folders', $messageRoute, $messageRoute.'/body', $messageRoute.'/raw', $messageRoute.'/attachments', $messageRoute.'/attachments/a0','/webmail/messages?folders='.urlencode($scope)] as $path) {
        $r=$request('bob',$path);$assert($r['status']===404,'Ownership: '.strtok($path,'?'));
    }
    foreach([$messageRoute.'9999'=>$messageRoute.'9999', 'bad-id'=>'/webmail/accounts/'.$account.'/messages/..%2F..%2Fconfig', 'attachment'=>$messageRoute.'/attachments/a999', 'limit'=>'/webmail/messages?limit=-1&folders='.urlencode($scope), 'cursor'=>'/webmail/messages?cursor=broken&folders='.urlencode($scope), 'folder'=>'/webmail/messages?folders='.urlencode($account.'~missing')] as $label=>$path) {
        $r=$request('alice',$path);$assert(in_array($r['status'],[400,404,422],true),'Fehlerhafte oder fehlende Ressource: '.$label);
    }
    $r=$request('alice','/accounts/'.$account,'PUT','{"broken"');$assert($r['status']===422,'Ungültiger JSON-Body');
    foreach([['columns'=>[['unexpected']]],['favorites'=>[['name'=>'Invalid','folders'=>[$scope],'filters'=>'invalid']]],['pageSize'=>1000]] as $invalid) {
        $r=$request('alice','/webmail/settings','PUT',$invalid);
        $assert($r['status']===422 && $r['json']['ok']===false,'Ungültige Anzeigeeinstellungen abgewiesen');
    }
    $r=$request('alice','/webmail/settings','PUT',['pageSize'=>40,'density'=>'compact','favorites'=>[['name'=>'Mein Eingang','folders'=>[$scope],'filters'=>['q'=>'Jörg']]]]);
    $assert($r['status']===200 && $r['data']['pageSize']===40,'Benutzerpräferenzen und Favorit speichern');
    $originalPreferences=$r['data'];
    $newColumns=['bytes','from','to','cc'];
    $widths=['bytes'=>90,'from'=>200,'to'=>230,'cc'=>210];
    ksort($widths, SORT_STRING); // JsonStore canonicalizes object keys on disk.
    $r=$request('alice','/webmail/settings','PUT',array_replace($originalPreferences,['columns'=>array_merge($originalPreferences['columns'],$newColumns),'columnWidths'=>$widths]));
    $assert($r['status']===200 && array_diff($newColumns,$r['data']['columns'])===[] && $r['data']['columnWidths']===$widths,'Neue Spalten und Breiten speicherbar');
    $r=$request('alice','/webmail/settings');
    $assert(array_diff($newColumns,$r['data']['columns'])===[] && $r['data']['columnWidths']===$widths,'Neue Spalten bleiben nach erneutem Abruf erhalten');
    $defaultOrder=$r['data']['columnOrder'];
    $assert(count($defaultOrder)===14 && $defaultOrder[0]==='message' && count(array_unique($defaultOrder))===14,'Bestehende Einstellungen erhalten vollständige Standard-Spaltenreihenfolge');
    $reordered=array_reverse($defaultOrder);
    $r=$request('alice','/webmail/settings','PUT',array_replace($r['data'],['columnOrder'=>$reordered]));
    $assert($r['status']===200 && $r['data']['columnOrder']===$reordered,'Benutzerdefinierte Spaltenreihenfolge einschließlich Nachricht speicherbar');
    $r=$request('alice','/webmail/settings');
    $assert($r['data']['columnOrder']===$reordered && $r['data']['columnWidths']===$widths,'Reihenfolge und Breiten bleiben nach erneutem Abruf erhalten');
    $r=$request('alice','/webmail/settings','PUT',['columns'=>['message','bytes']]);
    $assert($r['data']['columnOrder']===$reordered,'Ausblenden verändert die Position versteckter Spalten nicht');
    $r=$request('bob','/webmail/settings');
    $assert($r['data']['columnOrder']===$defaultOrder,'Spaltenreihenfolge ist benutzergetrennt');
    foreach(['invalid',['bytes','bytes'],['unknown'],[['nested']],['column'=>'bytes'],null] as $invalidOrder) {
        $r=$request('alice','/webmail/settings','PUT',['columnOrder'=>$invalidOrder]);
        $assert($r['status']===422,'Ungültige Spaltenreihenfolge abgewiesen');
    }
    $r=$request('alice','/webmail/settings','PUT',['columnOrder'=>['bytes','message']]);
    $assert($r['status']===200 && array_slice($r['data']['columnOrder'],0,2)===['bytes','message'] && count($r['data']['columnOrder'])===14,'Fehlende und neue Spalten werden eindeutig hinten ergänzt');
    $request('alice','/webmail/settings','PUT',$originalPreferences);
    $r=$request('bob','/webmail/settings');$assert($r['data']['pageSize']===100 && $r['data']['favorites']===[],'Präferenzen sind benutzergetrennt');
    $r=$request('alice','/accounts/'.$account.'/sync/status');$assert($r['status']===200 && $r['data']['job']===null,'Sync-Status ohne bisherigen Job');
    $secret=$app['paths']->secret($users['alice'],$account,'imap-main');
    unlink($secret);
    $r=$request('alice','/accounts/'.$account.'/sync/start','POST');$assert($r['data']['job']['state']==='queued','Bestehenden Sync starten');
    $r=$request('alice','/accounts/'.$account.'/sync/advance','POST');$assert($r['data']['job']['state']==='failed' && $r['data']['job']['last_error']['retryable']===false,'Sync-Fehler: fehlende Credentials sind nicht retrybar');
    $assert(!str_contains($r['raw'],$root),'Sync-Konfigurationsfehler verrät keine privaten Pfade');
    $r=$request('alice','/accounts/'.$account,'PUT',['imap_password'=>'replacement-secret','label'=>'Repaired']);
    $assert($r['status']===200 && $r['data']['account']['credential_status']['imap_configured'],'Fehlendes Passwort über Einstellungen reparierbar');
    $r=$request('alice','/accounts/'.$account,'PUT',['imap_password'=>'should-not-be-saved','label'=>'']);
    $assert($r['status']===422 && $app['credentials']->password($users['alice'],$account,'imap-main')==='replacement-secret','Ungültige Einstellungen verändern kein Passwort');
    $r=$request('alice','/logout','POST');$assert($r['status']===200,'Logout');
    $r=$request('alice','/session');$assert($r['data']['authenticated']===false,'Session nach Logout ungültig');
    $r=$request('alice','/login','POST',['username'=>'alice','password'=>'bad-password']);$assert($r['status']===401,'Falsches Login abgewiesen');
    $r=$request('alice','/login','POST',['username'=>'alice','password'=>'fixture-password-1234']);$assert($r['status']===200,'Bestehendes Login wiederverwendet');
    $start=microtime(true);
    $r=$waitForPage($account.'~'.$map['Large']);
    $assert($r['status']===200 && $r['data']['total']===50000 && count($r['data']['messages'])===100,'50.000 Nachrichten ohne Volltransfer oder Body-Laden');
    echo 'Large-folder cold index + first page: '.round(microtime(true)-$start,2)." s\n";
    $start=microtime(true);
    $r=$request('alice','/webmail/messages?'.http_build_query(['folders'=>$account.'~'.$map['Large'],'limit'=>100,'cursor'=>$r['data']['nextCursor']]));
    $assert($r['status']===200 && count($r['data']['messages'])===100,'Warme Folgeseite bei 50.000 Nachrichten');
    echo 'Large-folder warm page: '.round(microtime(true)-$start,2)." s; response ".strlen($r['raw'])." bytes\n";
    $s=new HtmlSanitizer();
    foreach([
        '<svg><a xlink:href="javascript:alert(1)">X</a></svg><math><mtext><img src=x onerror=alert(1)>',
        '<img src="&#106;avascript:alert(1)"><a href="data:text/html,evil">X</a><form action="/api.php/logout"><input autofocus onfocus=alert(1)></form>',
        '<div style="background-image:url(https://tracker.test);position:fixed">X</div><style>@import "https://tracker.test"</style><meta http-equiv=refresh content="0;url=https://tracker.test">',
    ] as $attack) {
        $safe=$s->sanitize($attack);
        $assert(!preg_match('/<script|onerror|onfocus|javascript:|data:text|<form|<svg|<math|<input|http-equiv=refresh|tracker\.test/i',$safe),'Adversariale HTML-Sanitization');
    }
    echo "\n{$passed} Webmail-Prüfungen bestanden.\n";
    if (in_array('--serve', $argv, true)) {
        // Dedicated synthetic browser QA environment, never the user's mailstore.
        echo "BROWSER_QA_URL=http://{$address}{$mount}/\n";
        echo "BROWSER_QA_USER=alice BROWSER_QA_PASSWORD=fixture-password-1234\n";
        fflush(STDOUT);
        $serving=true;
        if(function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM,static function() use (&$serving):void {$serving=false;});
            pcntl_signal(SIGINT,static function() use (&$serving):void {$serving=false;});
        }
        while ($serving) sleep(30);
    }
} finally {
    proc_terminate($process);proc_close($process);
    $delete=static function(string $path) use (&$delete):void {
        if(is_dir($path)&&!is_link($path)){foreach(array_diff(scandir($path),['.','..']) as $name)$delete($path.'/'.$name);rmdir($path);}else if(file_exists($path)||is_link($path))unlink($path);
    };
    $delete($root);
}
