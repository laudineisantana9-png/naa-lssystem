<?php
declare(strict_types=1);

const NAA_DATA_DIR = __DIR__ . '/data';

function naa_boot_storage(): void {
    if (!is_dir(NAA_DATA_DIR)) @mkdir(NAA_DATA_DIR, 0775, true);
    foreach (['datasets','photos'] as $d) {
        $p = NAA_DATA_DIR . '/' . $d;
        if (!is_dir($p)) @mkdir($p, 0775, true);
    }
    $ht = NAA_DATA_DIR . '/.htaccess';
    if (!is_file($ht)) @file_put_contents($ht, "Require all denied\nDeny from all\nOptions -Indexes\n");
    /* V6.10.3: remove temporários antigos deixados por gravações interrompidas.
       Em hospedagem compartilhada milhares de .tmp podem consumir inodes e
       provocar HTTP 507 mesmo com arquivos JSON pequenos. */
    foreach (glob(NAA_DATA_DIR . '/*.tmp.*') ?: [] as $tmp) {
        if (@is_file($tmp) && (@filemtime($tmp) ?: 0) < time() - 300) @unlink($tmp);
    }
}
naa_boot_storage();

function naa_respond(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function naa_origin_guard(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($origin && $host) {
        $originHost = parse_url($origin, PHP_URL_HOST);
        $hostOnly = preg_replace('/:\d+$/', '', $host);
        if ($originHost && strcasecmp((string)$originHost, (string)$hostOnly) !== 0) {
            naa_respond(['ok'=>false,'error'=>'Origem não autorizada.'], 403);
        }
    }
}

function naa_input(): array {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') naa_respond(['ok'=>false,'error'=>'Método não permitido.'],405);
    naa_origin_guard();
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) naa_respond(['ok'=>false,'error'=>'JSON inválido.'],400);
    return $input;
}

function naa_file(string $name): string { return NAA_DATA_DIR . '/' . $name . '.json'; }

/* =========================================================
 * NAA 7.0 · STORAGE HÍBRIDO JSON + MYSQL/MARIADB
 * Mantém compatibilidade total com as APIs atuais. Quando SQL está ativo,
 * cada coleção do NAA é persistida como um payload transacional em naa_store.
 * O JSON pode permanecer como espelho de continuidade/offline.
 * ========================================================= */
function naa_db_config(): array {
    static $cfg = null;
    if (is_array($cfg)) return $cfg;
    $path = dirname(__DIR__) . '/config/naa-database.php';
    $cfg = is_file($path) ? (array)require $path : [];
    return array_merge([
        'enabled'=>false,'host'=>'localhost','port'=>3306,'database'=>'naa',
        'username'=>'','password'=>'','charset'=>'utf8mb4','mirror_json'=>true,
        'fallback_json'=>true,'auto_migrate'=>true
    ], $cfg);
}

function naa_sql_pdo(): ?PDO {
    static $done = false, $pdo = null;
    if ($done) return $pdo;
    $done = true;
    $cfg = naa_db_config();
    if (empty($cfg['enabled']) || !class_exists('PDO')) return null;
    try {
        $host = preg_replace('/[^a-zA-Z0-9_.:-]/', '', (string)$cfg['host']);
        $port = max(1, (int)$cfg['port']);
        $db = preg_replace('/[^a-zA-Z0-9_$-]/', '', (string)$cfg['database']);
        $charset = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$cfg['charset']) ?: 'utf8mb4';
        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
        $pdo = new PDO($dsn, (string)$cfg['username'], (string)$cfg['password'], [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
            PDO::ATTR_TIMEOUT=>5,
        ]);
        if (!empty($cfg['auto_migrate'])) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS naa_store (
                store_key VARCHAR(120) NOT NULL PRIMARY KEY,
                payload LONGTEXT NOT NULL,
                checksum CHAR(64) DEFAULT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        return $pdo;
    } catch (Throwable $e) {
        error_log('[NAA SQL] '.$e->getMessage());
        $pdo = null;
        return null;
    }
}

function naa_storage_status(): array {
    $cfg = naa_db_config();
    $pdo = naa_sql_pdo();
    return [
        'configured'=>!empty($cfg['enabled']),
        'driver'=>$pdo ? 'mysql' : 'json',
        'sqlAvailable'=>(bool)$pdo,
        'mirrorJson'=>!empty($cfg['mirror_json']),
        'fallbackJson'=>!empty($cfg['fallback_json']),
    ];
}

function naa_json_read(string $name, array $default = []): array {
    $file = naa_file($name);
    if (!is_file($file)) return $default;
    $fp = @fopen($file, 'rb');
    if (!$fp) return $default;
    try {
        flock($fp, LOCK_SH);
        $raw = stream_get_contents($fp);
        flock($fp, LOCK_UN);
    } finally { fclose($fp); }
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : $default;
}

function naa_json_write(string $name, array $data): bool {
    $file = naa_file($name);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    $fp = @fopen($file, 'c+b');
    if (!$fp) return false;
    $ok = false;
    try {
        if (!flock($fp, LOCK_EX)) return false;
        rewind($fp);
        if (!ftruncate($fp, 0)) { flock($fp, LOCK_UN); return false; }
        $len = strlen($json); $written = 0;
        while ($written < $len) {
            $n = fwrite($fp, substr($json, $written));
            if ($n === false || $n === 0) break;
            $written += $n;
        }
        fflush($fp);
        $ok = ($written === $len);
        flock($fp, LOCK_UN);
    } finally { fclose($fp); }
    @chmod($file, 0660);
    return $ok;
}

function naa_sql_write_payload(PDO $pdo, string $name, array $data): bool {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    $checksum = hash('sha256', $json);
    $st = $pdo->prepare("INSERT INTO naa_store (store_key,payload,checksum,updated_at)
        VALUES (?,?,?,UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE payload=VALUES(payload),checksum=VALUES(checksum),updated_at=UTC_TIMESTAMP()");
    return $st->execute([$name,$json,$checksum]);
}

function naa_read(string $name, array $default = []): array {
    $cfg = naa_db_config();
    $pdo = naa_sql_pdo();
    if ($pdo) {
        try {
            $st = $pdo->prepare('SELECT payload FROM naa_store WHERE store_key=? LIMIT 1');
            $st->execute([$name]);
            $raw = $st->fetchColumn();
            if (is_string($raw) && $raw !== '') {
                $data = json_decode($raw, true);
                if (is_array($data)) return $data;
            }
            /* Migração transparente: se a coleção ainda só existe em JSON,
               leva o conteúdo para SQL na primeira leitura. */
            $legacy = naa_json_read($name, $default);
            if ($legacy !== $default || is_file(naa_file($name))) {
                naa_sql_write_payload($pdo, $name, $legacy);
            }
            return $legacy;
        } catch (Throwable $e) {
            error_log('[NAA SQL read '.$name.'] '.$e->getMessage());
            if (empty($cfg['fallback_json'])) return $default;
        }
    }
    return naa_json_read($name, $default);
}

function naa_write(string $name, array $data): bool {
    $cfg = naa_db_config();
    $pdo = naa_sql_pdo();
    if ($pdo) {
        try {
            $ok = naa_sql_write_payload($pdo, $name, $data);
            if ($ok && !empty($cfg['mirror_json'])) naa_json_write($name, $data);
            return $ok;
        } catch (Throwable $e) {
            error_log('[NAA SQL write '.$name.'] '.$e->getMessage());
            if (empty($cfg['fallback_json'])) return false;
        }
    }
    return naa_json_write($name, $data);
}

function naa_write_required(string $name, array $data): void {
    if (naa_write($name, $data)) return;
    $free = @disk_free_space(NAA_DATA_DIR);
    $detail = ($free !== false && $free < 5 * 1024 * 1024)
        ? 'O armazenamento do servidor está sem espaço livre.'
        : 'O servidor não conseguiu gravar os arquivos da reunião. Verifique espaço e permissão da pasta api/data.';
    naa_respond(['ok'=>false,'error'=>$detail], 507);
}

function naa_id(string $prefix='id'): string { return $prefix . '_' . bin2hex(random_bytes(12)); }
function naa_now(): string { return gmdate('c'); }
function naa_digits(string $v): string { return preg_replace('/\D+/', '', $v) ?? ''; }
function naa_norm_email(string $v): string { return strtolower(trim($v)); }
function naa_len(string $v): int { return function_exists('mb_strlen') ? mb_strlen($v,'UTF-8') : strlen($v); }
function naa_substr(string $v, int $start, int $len): string { return function_exists('mb_substr') ? mb_substr($v,$start,$len,'UTF-8') : substr($v,$start,$len); }


function naa_role_meta(string $role): array {
    $roles = [
        'professor'=>['scope'=>'school'], 'coordenador'=>['scope'=>'school'], 'diretor'=>['scope'=>'school'],
        'vice_diretor'=>['scope'=>'school'], 'secretaria_escolar'=>['scope'=>'school'],
        'secretaria_educacao'=>['scope'=>'global'], 'administrativo'=>['scope'=>'global'], 'rede'=>['scope'=>'global'], 'pedagogico'=>['scope'=>'global'],
        'ceo_programador'=>['scope'=>'global','admin'=>true], 'nucleo_naa'=>['scope'=>'global','admin'=>true],
        'convidado'=>['scope'=>'guest'],
    ];
    return $roles[$role] ?? ['scope'=>'global'];
}
function naa_role_allowed(string $role): bool { return in_array($role,['professor','coordenador','diretor','vice_diretor','secretaria_escolar','secretaria_educacao','administrativo','rede','pedagogico','ceo_programador','nucleo_naa','convidado'],true); }
function naa_is_admin(array $u): bool { return !empty(naa_role_meta((string)($u['role']??''))['admin']); }
function naa_can_upload(array $u): bool { return naa_is_admin($u) || !empty($u['allowUpload']); }
function naa_can_create_meet(array $u): bool { return naa_is_admin($u) || !empty($u['allowMeetCreate']); }

function naa_public_user(array $u, bool $full=false): array {
    $base = [
        'id'=>(string)($u['id']??''), 'name'=>(string)($u['name']??''), 'role'=>(string)($u['role']??''),
        'school'=>(string)($u['school']??''), 'photo'=>(string)($u['photo']??''), 'active'=>($u['active']??true)!==false,
        'allowUpload'=>!empty($u['allowUpload']), 'allowMeetCreate'=>!empty($u['allowMeetCreate']),
    ];
    if ($full) $base += [
        'cpf'=>(string)($u['cpf']??''), 'email'=>(string)($u['email']??''), 'whatsapp'=>(string)($u['whatsapp']??''),
        'birthDate'=>(string)($u['birthDate']??''), 'createdAt'=>(string)($u['createdAt']??''), 'updatedAt'=>(string)($u['updatedAt']??''),
        'lastLogin'=>(string)($u['lastLogin']??''), 'forcePasswordChange'=>!empty($u['forcePasswordChange'])
    ];
    return $base;
}

function naa_find_user(string $id): ?array {
    foreach (naa_read('users') as $u) if (($u['id']??'') === $id) return $u;
    return null;
}

function naa_session_create(array $user, bool $persistent=true): string {
    $sessions = naa_read('sessions');
    $token = bin2hex(random_bytes(32));
    $sessions[] = [
        'tokenHash'=>hash('sha256',$token), 'userId'=>$user['id'], 'createdAt'=>naa_now(),
        'expiresAt'=>gmdate('c', time()+($persistent?60*60*24*30:60*60*12)),
        'uaHash'=>hash('sha256',(string)($_SERVER['HTTP_USER_AGENT']??''))
    ];
    $cut = time()-60*60*24*3;
    $sessions = array_values(array_filter($sessions, fn($s)=>strtotime((string)($s['expiresAt']??'')) > $cut));
    naa_write('sessions',$sessions);
    return $token;
}

function naa_auth(array $input): array {
    $token = trim((string)($input['token']??''));
    if ($token==='') naa_respond(['ok'=>false,'error'=>'Sessão necessária.'],401);
    $hash = hash('sha256',$token);
    foreach (naa_read('sessions') as $s) {
        if (!hash_equals((string)($s['tokenHash']??''),$hash)) continue;
        if (strtotime((string)($s['expiresAt']??'')) < time()) break;
        $u=naa_find_user((string)($s['userId']??''));
        if ($u && ($u['active']??true)!==false) return $u;
    }
    naa_respond(['ok'=>false,'error'=>'Sessão expirada. Entre novamente.'],401);
}

function naa_event_target_match(array $e, array $u): bool {
    if (!empty($e['userId'])) return (string)$e['userId']===(string)$u['id'];
    if (!empty($e['role'])) return (string)$e['role']===(string)$u['role'];
    if (!empty($e['school'])) return (string)$e['school']===(string)($u['school']??'');
    if (!empty($e['roles']) && is_array($e['roles'])) return in_array((string)$u['role'], $e['roles'], true);
    return true;
}

function naa_add_event(array $event): array {
    $events = naa_read('events');
    $last = end($events);
    $seq = (int)($last['seq']??0)+1;
    $event = ['seq'=>$seq,'createdAt'=>naa_now(),...$event];
    if (empty($event['id'])) $event['id']='evt_'.$seq;
    $events[]=$event;
    if (count($events)>5000) $events=array_slice($events,-4000);
    naa_write('events',$events);
    try { naa_push_dispatch_event($event); } catch (Throwable $ignore) {}
    return $event;
}



function naa_public_registration_role_allowed(string $role): bool {
    return in_array($role,[
        'professor','coordenador','diretor','vice_diretor','secretaria_escolar',
        'secretaria_educacao','administrativo','rede','pedagogico'
    ],true);
}

function naa_role_label(string $role): string {
    return [
        'professor'=>'Professor','coordenador'=>'Coordenador','diretor'=>'Diretor','vice_diretor'=>'Vice-diretor',
        'secretaria_escolar'=>'Secretaria Escolar','secretaria_educacao'=>'Secretaria de Educação',
        'administrativo'=>'Administrativo','rede'=>'Rede','pedagogico'=>'Pedagógico',
        'ceo_programador'=>'CEO Programador','nucleo_naa'=>'Núcleo (NAA)','convidado'=>'Convidado LS Meet'
    ][$role] ?? $role;
}

function naa_valid_photo_data(string $photo): bool {
    if ($photo==='') return false;
    if (!preg_match('#^data:image/(jpeg|jpg|png|webp);base64,#i',$photo,$m)) return false;
    $parts=explode(',',$photo,2);
    if(count($parts)!==2) return false;
    $raw=base64_decode($parts[1],true);
    if($raw===false) return false;
    $len=strlen($raw);
    return $len>=1024 && $len<=2*1024*1024;
}

function naa_valid_cpf(string $cpf): bool {
    $cpf = naa_digits($cpf);
    if (strlen($cpf)!==11 || preg_match('/^(\d)\1{10}$/',$cpf)) return false;
    for ($t=9;$t<11;$t++) {
        $sum=0;
        for($i=0;$i<$t;$i++) $sum += ((int)$cpf[$i]) * (($t+1)-$i);
        $digit=(10*$sum)%11; if($digit===10)$digit=0;
        if($digit!==(int)$cpf[$t]) return false;
    }
    return true;
}

function naa_valid_whatsapp(string $value): bool {
    $d=naa_digits($value);
    return strlen($d)===10 || strlen($d)===11;
}

function naa_mask_cpf(string $cpf): string {
    $d=naa_digits($cpf);
    if(strlen($d)!==11) return $d;
    return '***.'.substr($d,3,3).'.'.substr($d,6,3).'-**';
}

function naa_registration_public(array $r, bool $full=false): array {
    $base=[
        'id'=>(string)($r['id']??''),'protocol'=>(string)($r['protocol']??''),'status'=>(string)($r['status']??'pending'),
        'name'=>(string)($r['name']??''),'role'=>(string)($r['role']??''),'school'=>(string)($r['school']??''),
        'photo'=>(string)($r['photo']??''),'createdAt'=>(string)($r['createdAt']??''),'updatedAt'=>(string)($r['updatedAt']??''),
        'rejectionReason'=>(string)($r['rejectionReason']??'')
    ];
    if($full){
        $base += [
            'cpf'=>(string)($r['cpf']??''),'email'=>(string)($r['email']??''),'whatsapp'=>(string)($r['whatsapp']??''),
            'birthDate'=>(string)($r['birthDate']??''),'approvedAt'=>(string)($r['approvedAt']??''),
            'approvedBy'=>(string)($r['approvedBy']??''),'userId'=>(string)($r['userId']??'')
        ];
    } else {
        $base['cpfMasked']=naa_mask_cpf((string)($r['cpf']??''));
    }
    return $base;
}

function naa_collect_schools(): array {
    $schools=naa_read('schools');
    $map=[];
    foreach($schools as $s){$s=trim((string)$s);if($s!=='')$map[$s]=true;}
    $dir=NAA_DATA_DIR.'/datasets';
    foreach(glob($dir.'/*.json')?:[] as $file){
        $data=json_decode((string)@file_get_contents($file),true);
        if(!is_array($data))continue;
        foreach(array_merge($data['rows']??[],$data['students']??[]) as $row){
            $s=trim((string)($row['school']??''));if($s!=='')$map[$s]=true;
        }
    }
    foreach(naa_read('users') as $u){$s=trim((string)($u['school']??''));if($s!=='')$map[$s]=true;}
    $out=array_keys($map);
    usort($out,'strnatcasecmp');
    return $out;
}

function naa_can_see_user(array $viewer, array $target): bool {
    if((string)($viewer['id']??'')===(string)($target['id']??'')) return true;
    if(naa_is_admin($viewer)) return true;
    $vm=naa_role_meta((string)($viewer['role']??''));
    if(($vm['scope']??'global')==='global') return true;
    if(($vm['scope']??'global')==='guest') return naa_is_admin($target);
    $viewerSchool=trim((string)($viewer['school']??''));
    if($viewerSchool==='' ) return false;
    $tm=naa_role_meta((string)($target['role']??''));
    if(($tm['scope']??'global')==='global') return true;
    return $viewerSchool===trim((string)($target['school']??''));
}

function naa_filter_dataset_for_user(array $d, array $u): array {
    $meta=naa_role_meta((string)($u['role']??''));
    if (($meta['scope']??'global')==='guest') {
        $d['rows']=[];
        $d['students']=[];
        $d['municipalSkills']=[];
        $d['items']=[];
        return $d;
    }
    if (($meta['scope']??'global')!=='school' || empty($u['school'])) return $d;
    $school=(string)$u['school'];
    $d['rows']=array_values(array_filter($d['rows']??[],fn($r)=>(string)($r['school']??'')===$school));
    $d['students']=array_values(array_filter($d['students']??[],fn($r)=>(string)($r['school']??'')===$school));
    $d['items']=array_values(array_filter($d['items']??[],fn($r)=>(string)($r['school']??'')===$school));
    $d['municipalSkills']=[];
    return $d;
}

/* =========================================================
 * WEB PUSH / VAPID — NAA (sem dependência externa)
 * RFC 8291 + RFC 8292, usando OpenSSL do PHP.
 * ========================================================= */
function naa_b64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function naa_b64url_decode(string $data): string|false {
    $data = strtr(trim($data), '-_', '+/');
    $pad = strlen($data) % 4;
    if ($pad) $data .= str_repeat('=', 4 - $pad);
    return base64_decode($data, true);
}

function naa_push_config(): array {
    static $memory = null;
    if (is_array($memory) && !empty($memory['privatePem']) && !empty($memory['publicKey'])) return $memory;

    $file = naa_file('push_vapid');
    $cfg = naa_read('push_vapid');
    if (!empty($cfg['privatePem']) && !empty($cfg['publicKey']) && !empty($cfg['receiptSecret'])) {
        return $memory = $cfg;
    }

    if (!extension_loaded('openssl')) {
        throw new RuntimeException('OpenSSL é necessário para ativar Web Push.');
    }

    $key = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
    ]);
    if ($key === false) throw new RuntimeException('Não foi possível gerar a chave VAPID.');

    $privatePem = '';
    if (!openssl_pkey_export($key, $privatePem)) throw new RuntimeException('Não foi possível exportar a chave VAPID.');
    $details = openssl_pkey_get_details($key);
    $x = (string)($details['ec']['x'] ?? '');
    $y = (string)($details['ec']['y'] ?? '');
    if ($x === '' || $y === '') throw new RuntimeException('A chave VAPID gerada não contém coordenadas EC.');
    $x = str_pad($x, 32, "\0", STR_PAD_LEFT);
    $y = str_pad($y, 32, "\0", STR_PAD_LEFT);
    $rawPublic = "\x04" . $x . $y;

    $cfg = [
        'privatePem' => $privatePem,
        'publicKey' => naa_b64url_encode($rawPublic),
        'receiptSecret' => naa_b64url_encode(random_bytes(32)),
        'createdAt' => naa_now(),
    ];
    if (!naa_write('push_vapid', $cfg)) throw new RuntimeException('Não foi possível salvar a configuração VAPID.');
    @chmod($file, 0660);
    return $memory = $cfg;
}

function naa_push_public_key(): string {
    return (string)(naa_push_config()['publicKey'] ?? '');
}

function naa_push_receipt_token(string $messageId, string $userId): string {
    $secret = naa_b64url_decode((string)(naa_push_config()['receiptSecret'] ?? ''));
    if ($secret === false || $secret === '') throw new RuntimeException('Segredo de confirmação Push indisponível.');
    return naa_b64url_encode(hash_hmac('sha256', $messageId . '|' . $userId, $secret, true));
}

function naa_push_receipt_valid(string $messageId, string $userId, string $token): bool {
    if ($messageId === '' || $userId === '' || $token === '') return false;
    try { $expected = naa_push_receipt_token($messageId, $userId); }
    catch (Throwable $e) { return false; }
    return hash_equals($expected, $token);
}

function naa_push_store_subscription(string $userId, array $subscription, string $userAgent = ''): array {
    $endpoint = trim((string)($subscription['endpoint'] ?? ''));
    $keys = is_array($subscription['keys'] ?? null) ? $subscription['keys'] : [];
    $p256dh = trim((string)($keys['p256dh'] ?? ''));
    $auth = trim((string)($keys['auth'] ?? ''));
    if ($endpoint === '' || !filter_var($endpoint, FILTER_VALIDATE_URL) || $p256dh === '' || $auth === '') {
        throw new InvalidArgumentException('Inscrição Web Push inválida.');
    }
    if (!str_starts_with(strtolower($endpoint), 'https://')) throw new InvalidArgumentException('O endpoint Web Push deve usar HTTPS.');
    if (naa_b64url_decode($p256dh) === false || naa_b64url_decode($auth) === false) throw new InvalidArgumentException('Chaves Web Push inválidas.');

    $subs = naa_read('push_subscriptions');
    $now = naa_now();
    $found = false;
    foreach ($subs as &$row) {
        if ((string)($row['endpoint'] ?? '') !== $endpoint) continue;
        $row['userId'] = $userId;
        $row['p256dh'] = $p256dh;
        $row['auth'] = $auth;
        $row['updatedAt'] = $now;
        $row['userAgent'] = naa_substr($userAgent, 0, 300);
        $row['failures'] = 0;
        $found = true;
        break;
    }
    unset($row);
    if (!$found) {
        $subs[] = [
            'id' => naa_id('push'), 'userId' => $userId, 'endpoint' => $endpoint,
            'p256dh' => $p256dh, 'auth' => $auth, 'createdAt' => $now, 'updatedAt' => $now,
            'userAgent' => naa_substr($userAgent, 0, 300), 'failures' => 0,
        ];
    }
    if (count($subs) > 5000) $subs = array_slice($subs, -4000);
    naa_write('push_subscriptions', $subs);
    return ['endpoint'=>$endpoint,'created'=>!$found];
}

function naa_push_remove_subscription(string $userId, string $endpoint): bool {
    $endpoint = trim($endpoint);
    if ($endpoint === '') return false;
    $subs = naa_read('push_subscriptions');
    $before = count($subs);
    $subs = array_values(array_filter($subs, fn($s)=>!((string)($s['userId']??'')===$userId && (string)($s['endpoint']??'')===$endpoint)));
    if (count($subs) !== $before) naa_write('push_subscriptions', $subs);
    return count($subs) !== $before;
}

function naa_ec_public_raw_to_pem(string $raw): string {
    if (strlen($raw) !== 65 || $raw[0] !== "\x04") throw new InvalidArgumentException('Chave pública P-256 inválida.');
    // SubjectPublicKeyInfo: id-ecPublicKey + prime256v1 + BIT STRING de 65 bytes.
    $der = hex2bin('3059301306072A8648CE3D020106082A8648CE3D030107034200') . $raw;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function naa_hkdf_sha256(string $ikm, int $length, string $info, string $salt): string {
    return hash_hkdf('sha256', $ikm, $length, $info, $salt);
}

function naa_webpush_encrypt(string $jsonPayload, string $clientP256dh, string $clientAuth): array {
    $uaPublic = naa_b64url_decode($clientP256dh);
    $authSecret = naa_b64url_decode($clientAuth);
    if ($uaPublic === false || strlen($uaPublic) !== 65 || $authSecret === false || strlen($authSecret) < 16) {
        throw new InvalidArgumentException('Material criptográfico da inscrição Push inválido.');
    }

    $asKey = openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);
    if ($asKey === false) throw new RuntimeException('Falha ao gerar chave efêmera Web Push.');
    $asDetails = openssl_pkey_get_details($asKey);
    $asX = str_pad((string)($asDetails['ec']['x'] ?? ''), 32, "\0", STR_PAD_LEFT);
    $asY = str_pad((string)($asDetails['ec']['y'] ?? ''), 32, "\0", STR_PAD_LEFT);
    if (strlen($asX)!==32 || strlen($asY)!==32) throw new RuntimeException('Chave efêmera Web Push inválida.');
    $asPublic = "\x04" . $asX . $asY;

    $uaPem = naa_ec_public_raw_to_pem($uaPublic);
    $uaKey = openssl_pkey_get_public($uaPem);
    if ($uaKey === false) throw new RuntimeException('Não foi possível importar a chave do navegador.');
    $shared = openssl_pkey_derive($uaKey, $asKey, 32);
    if ($shared === false || strlen($shared) !== 32) throw new RuntimeException('Falha no ECDH do Web Push.');

    $info = "WebPush: info\x00" . $uaPublic . $asPublic;
    $ikm = naa_hkdf_sha256($shared, 32, $info, $authSecret);
    $salt = random_bytes(16);
    $cek = naa_hkdf_sha256($ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
    $nonce = naa_hkdf_sha256($ikm, 12, "Content-Encoding: nonce\x00", $salt);

    // Em aes128gcm, 0x02 finaliza o único registro usado pelo payload.
    $plaintext = $jsonPayload . "\x02";
    $tag = '';
    $cipher = openssl_encrypt($plaintext, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($cipher === false || strlen($tag)!==16) throw new RuntimeException('Falha ao criptografar o Web Push.');

    $recordSize = 4096;
    $body = $salt . pack('N', $recordSize) . chr(strlen($asPublic)) . $asPublic . $cipher . $tag;
    return ['body'=>$body,'serverPublic'=>$asPublic];
}

function naa_der_length(string $der, int &$offset): int {
    if ($offset >= strlen($der)) throw new RuntimeException('Assinatura DER inválida.');
    $first = ord($der[$offset++]);
    if (($first & 0x80) === 0) return $first;
    $count = $first & 0x7f;
    if ($count < 1 || $count > 4 || $offset + $count > strlen($der)) throw new RuntimeException('Comprimento DER inválido.');
    $len = 0;
    for ($i=0;$i<$count;$i++) $len = ($len << 8) | ord($der[$offset++]);
    return $len;
}

function naa_ecdsa_der_to_jose(string $der, int $partSize=32): string {
    $o=0;
    if (($der[$o++] ?? '') !== "\x30") throw new RuntimeException('Assinatura ECDSA inválida.');
    naa_der_length($der,$o);
    if (($der[$o++] ?? '') !== "\x02") throw new RuntimeException('Assinatura ECDSA sem R.');
    $rLen=naa_der_length($der,$o);$r=substr($der,$o,$rLen);$o+=$rLen;
    if (($der[$o++] ?? '') !== "\x02") throw new RuntimeException('Assinatura ECDSA sem S.');
    $sLen=naa_der_length($der,$o);$s=substr($der,$o,$sLen);
    $r=ltrim($r,"\0");$s=ltrim($s,"\0");
    if(strlen($r)>$partSize)$r=substr($r,-$partSize);if(strlen($s)>$partSize)$s=substr($s,-$partSize);
    return str_pad($r,$partSize,"\0",STR_PAD_LEFT).str_pad($s,$partSize,"\0",STR_PAD_LEFT);
}

function naa_vapid_jwt(string $endpoint): array {
    $cfg=naa_push_config();
    $parts=parse_url($endpoint);
    if(!$parts||empty($parts['scheme'])||empty($parts['host']))throw new InvalidArgumentException('Endpoint Push inválido.');
    $aud=$parts['scheme'].'://'.$parts['host'].(!empty($parts['port'])?':'.$parts['port']:'');
    $header=naa_b64url_encode(json_encode(['typ'=>'JWT','alg'=>'ES256'],JSON_UNESCAPED_SLASHES));
    $claims=naa_b64url_encode(json_encode(['aud'=>$aud,'exp'=>time()+12*3600,'sub'=>'mailto:naa@lssystem.com.br'],JSON_UNESCAPED_SLASHES));
    $input=$header.'.'.$claims;$der='';
    $key=openssl_pkey_get_private((string)$cfg['privatePem']);
    if($key===false||!openssl_sign($input,$der,$key,OPENSSL_ALGO_SHA256))throw new RuntimeException('Falha ao assinar VAPID.');
    $jwt=$input.'.'.naa_b64url_encode(naa_ecdsa_der_to_jose($der));
    return ['jwt'=>$jwt,'publicKey'=>(string)$cfg['publicKey']];
}

function naa_webpush_send_subscription(array $sub, array $payload, int $ttl=120, string $urgency='normal'): array {
    $endpoint=(string)($sub['endpoint']??'');
    if($endpoint==='')return ['ok'=>false,'status'=>0,'error'=>'Endpoint ausente'];
    $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if($json===false) return ['ok'=>false,'status'=>0,'error'=>'Payload inválido'];
    if(strlen($json)>3000) $json=json_encode(['title'=>$payload['title']??'NAA','body'=>naa_substr((string)($payload['body']??''),0,500),'url'=>$payload['url']??'./index.html','kind'=>$payload['kind']??'notice','eventId'=>$payload['eventId']??''],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

    try {
        $encrypted=naa_webpush_encrypt((string)$json,(string)($sub['p256dh']??''),(string)($sub['auth']??''));
        $vapid=naa_vapid_jwt($endpoint);
        $headers=[
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: '.max(0,min(2419200,$ttl)),
            'Urgency: '.(in_array($urgency,['very-low','low','normal','high'],true)?$urgency:'normal'),
            'Authorization: vapid t='.$vapid['jwt'].', k='.$vapid['publicKey'],
            'Content-Length: '.strlen($encrypted['body']),
        ];
        $status=0;$response='';
        if(function_exists('curl_init')){
            $ch=curl_init($endpoint);
            curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$encrypted['body'],CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>10,CURLOPT_FOLLOWLOCATION=>false]);
            $response=(string)curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=(string)curl_error($ch);curl_close($ch);
            if($status===0&&$err!=='')return ['ok'=>false,'status'=>0,'error'=>$err];
        }else{
            $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$headers),'content'=>$encrypted['body'],'timeout'=>10,'ignore_errors'=>true]]);
            $response=(string)@file_get_contents($endpoint,false,$ctx);
            foreach($http_response_header??[] as $line)if(preg_match('#^HTTP/\S+\s+(\d+)#',$line,$m)){$status=(int)$m[1];break;}
        }
        return ['ok'=>in_array($status,[200,201,202],true),'status'=>$status,'response'=>naa_substr($response,0,500),'gone'=>in_array($status,[404,410],true)];
    } catch(Throwable $e){ return ['ok'=>false,'status'=>0,'error'=>$e->getMessage()]; }
}

function naa_push_send_user(string $userId, array $payload, int $ttl=120, string $urgency='normal'): array {
    if($userId==='')return ['sent'=>0,'failed'=>0];
    $subs=naa_read('push_subscriptions');$sent=0;$failed=0;$changed=false;
    foreach($subs as $i=>&$sub){
        if((string)($sub['userId']??'')!==$userId)continue;
        $result=naa_webpush_send_subscription($sub,$payload,$ttl,$urgency);
        if(!empty($result['ok'])){$sent++;$sub['lastSuccessAt']=naa_now();$sub['failures']=0;$changed=true;}
        else{$failed++;$sub['lastFailureAt']=naa_now();$sub['failures']=(int)($sub['failures']??0)+1;$changed=true;if(!empty($result['gone'])){$sub['_remove']=true;}}
    }
    unset($sub);
    if($changed){$subs=array_values(array_filter($subs,fn($s)=>empty($s['_remove'])));foreach($subs as &$s)unset($s['_remove']);unset($s);naa_write('push_subscriptions',$subs);}
    return ['sent'=>$sent,'failed'=>$failed];
}

function naa_push_dispatch_event(array $event): void {
    $type=(string)($event['type']??'');
    if(!in_array($type,['message','direct_call','notice','cycle','room','meet_waiting','access_approved','registration_request'],true))return;

    $title=(string)($event['title']??'NAA — Núcleo de Avaliação');
    $body=(string)($event['body']??'');
    if($type==='message'){$title='Mensagem de '.((string)($event['fromName']??'NAA'));$body=(string)($event['preview']??'Nova mensagem');}
    if($type==='cycle'&&empty($event['title'])){$title='Novo ciclo disponível';$body=trim(((string)($event['cycle']??'Novo ciclo')).' · '.((string)($event['refYear']??'')));}
    if($type==='room'&&empty($event['title']))$title='LS Meet · nova reunião';
    $url=(string)($event['url']??'./index.html');
    $eventId=(string)($event['id']??('evt_'.($event['seq']??naa_id('evt'))));
    $senderId=(string)($event['fromId']??'');
    $senderPhotoUrl='';
    if($senderId!==''){
        $photoToken=naa_push_receipt_token('photo',$senderId);
        $senderPhotoUrl='./api/naa-platform.php?action=push_photo&userId='.rawurlencode($senderId).'&token='.rawurlencode($photoToken);
    }else{
        $rawPhoto=(string)($event['senderPhoto']??'');
        if(preg_match('#^https?://#i',$rawPhoto))$senderPhotoUrl=$rawPhoto;
    }
    $payload=[
        'eventId'=>$eventId,'kind'=>$type,'title'=>$title,'body'=>$body,'url'=>$url,
        'senderPhoto'=>$senderPhotoUrl,'icon'=>$senderPhotoUrl,
        'messageImage'=>(string)($event['messageImage']??''),
        'messageId'=>(string)($event['messageId']??''),'contactId'=>$senderId,
        'roomId'=>(string)($event['roomId']??''),'mode'=>(string)($event['mode']??''),
        'callId'=>(string)($event['callId']??''),
        'callActionToken'=>(string)($event['callActionToken']??''),
        'receiptUserId'=>(string)($event['receiptUserId']??$event['userId']??''),
        'tag'=>'naa-'.$type.'-'.$eventId,
        'requireInteraction'=>$type==='direct_call',
    ];
    if($type==='message'&&!empty($event['messageId'])&&!empty($event['userId'])){
        $payload['deliveryToken']=naa_push_receipt_token((string)$event['messageId'],(string)$event['userId']);
        $payload['receiptUserId']=(string)$event['userId'];
    }
    if($type==='direct_call'){
        $payload['actions']=[['action'=>'answer','title'=>'Atender'],['action'=>'reject','title'=>'Recusar']];
        if(empty($payload['callActionToken'])&&!empty($payload['callId'])&&!empty($payload['receiptUserId'])){
            $payload['callActionToken']=naa_push_receipt_token('call-action-'.$payload['callId'],$payload['receiptUserId']);
        }
    }

    $targets=[];
    $users=naa_read('users');
    if(!empty($event['adminOnly'])){
        foreach($users as $u)if(($u['active']??true)!==false&&naa_is_admin($u))$targets[]=(string)$u['id'];
    }elseif(!empty($event['userId'])){
        $targets[]=(string)$event['userId'];
    }else{
        foreach($users as $u)if(($u['active']??true)!==false&&naa_event_target_match($event,$u))$targets[]=(string)$u['id'];
    }
    $targets=array_values(array_unique(array_filter($targets)));
    foreach($targets as $uid){try{naa_push_send_user($uid,$payload,$type==='direct_call'?120:86400,$type==='direct_call'?'high':'normal');}catch(Throwable $e){}}
}
