<?php
declare(strict_types=1);
require_once __DIR__ . '/naa-lib.php';

/* Foto assinada para notificações Push. O sistema operacional precisa de
 * uma URL HTTPS real; data URI é pouco confiável como ícone e aumenta demais
 * o payload criptografado do Web Push. */
if (in_array(($_SERVER['REQUEST_METHOD'] ?? 'GET'),['GET','HEAD'],true) && (string)($_GET['action'] ?? '') === 'push_photo') {
    $userId=trim((string)($_GET['userId']??''));
    $token=trim((string)($_GET['token']??''));
    if($userId===''||!naa_push_receipt_valid('photo',$userId,$token)){http_response_code(403);exit;}
    $user=naa_find_user($userId);
    $photo=(string)($user['photo']??'');
    if(!preg_match('#^data:image/(jpeg|jpg|png|webp);base64,#i',$photo,$m)){http_response_code(404);exit;}
    $parts=explode(',',$photo,2);
    $raw=isset($parts[1])?base64_decode($parts[1],true):false;
    if($raw===false){http_response_code(404);exit;}
    $mime=strtolower($m[1]);if($mime==='jpg')$mime='jpeg';
    header('Content-Type: image/'.$mime);
    header('Content-Length: '.strlen($raw));
    header('Cache-Control: public, max-age=86400, immutable');
    header('X-Content-Type-Options: nosniff');
    if(($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='HEAD')echo $raw;exit;
}

$in = naa_input();
$action = (string)($in['action'] ?? '');

/* =========================================================
 * CHAMADAS INDIVIDUAIS · V6.9
 * ========================================================= */
function naa_call_is_active_status(string $status): bool {
    return in_array($status,['ringing','accepted','connected'],true);
}
function naa_call_is_terminal_status(string $status): bool {
    return in_array($status,['busy','declined','cancelled','missed','ended','interrupted','failed'],true);
}
function naa_call_find_index(array $calls,string $callId): int {
    foreach($calls as $i=>$call) if((string)($call['id']??'')===$callId) return (int)$i;
    return -1;
}
function naa_call_duration_seconds(array $call): int {
    /* A duração começa somente quando áudio/vídeo realmente conectam.
       Atender ao Push não pode gerar tempo fictício de chamada. */
    $start=strtotime((string)($call['connectedAt']??''))?:0;
    $end=strtotime((string)($call['endedAt']??naa_now()))?:time();
    return $start>0?max(0,$end-$start):0;
}
function naa_call_history_label(array $call): string {
    $mode=(string)($call['mode']??'voice')==='video'?'video':'voice';
    $status=(string)($call['status']??'ended');
    $base=$mode==='video'?'Chamada de vídeo':'Chamada de voz';
    if($status==='busy')return $base.' · ocupado';
    if($status==='declined')return $base.' · recusada';
    if($status==='cancelled')return $base.' · cancelada';
    if($status==='missed')return $base.' · não atendida';
    if($status==='interrupted')return $base.' · interrompida';
    if($status==='failed')return $base.' · falha de conexão';
    return $base;
}
function naa_call_finalize_record(array &$calls,int $idx,string $status,string $actorId=''): array {
    if(!isset($calls[$idx])||!is_array($calls[$idx])) return [];
    $call=$calls[$idx];
    if(naa_call_is_terminal_status((string)($call['status']??'')) && !empty($call['historyMessageId'])) return $call;

    $now=naa_now();
    $call['status']=$status;
    if(empty($call['endedAt']))$call['endedAt']=$now;
    $call['endedBy']=$actorId;
    $call['durationSeconds']=naa_call_duration_seconds($call);

    if(empty($call['historyMessageId'])){
        $messages=naa_read('messages');
        $m=[
            'id'=>naa_id('msg'),
            'from'=>(string)($call['from']??''),
            'to'=>(string)($call['to']??''),
            'text'=>'',
            'kind'=>'call',
            'attachment'=>null,
            'meta'=>[
                'callId'=>(string)($call['id']??''),
                'mode'=>(string)($call['mode']??'voice'),
                'status'=>$status,
                'durationSeconds'=>(int)$call['durationSeconds'],
                'acceptedAt'=>(string)($call['acceptedAt']??''),
                'connectedAt'=>(string)($call['connectedAt']??''),
                'endedAt'=>(string)$call['endedAt'],
                'label'=>naa_call_history_label($call),
            ],
            'reply'=>null,
            'createdAt'=>(string)$call['endedAt'],
            'deliveredAt'=>(string)$call['endedAt'],
            'readAt'=>''
        ];
        $messages[]=$m;
        if(count($messages)>25000)$messages=array_slice($messages,-18000);
        naa_write('messages',$messages);
        $call['historyMessageId']=$m['id'];
    }
    $calls[$idx]=$call;
    return $call;
}
function naa_call_public(array $call,string $viewerId=''): array {
    $out=$call;
    unset($out['internalToken']);
    $out['viewerDirection']=$viewerId!==''&&$viewerId===(string)($call['from']??'')?'outgoing':'incoming';
    return $out;
}

/* =========================================================
 * ROTAS PÚBLICAS
 * ========================================================= */
if ($action === 'bootstrap_status') {
    naa_respond(['ok'=>true,'hasUsers'=>count(naa_read('users')) > 0]);
}

if ($action === 'global_settings_get') {
    $cfg=naa_read('global_settings');
    // configurações visuais/operacionais globais não contêm segredos.
    naa_respond(['ok'=>true,'settings'=>$cfg,'storage'=>naa_storage_status()]);
}

if ($action === 'storage_status') {
    naa_respond(['ok'=>true,'storage'=>naa_storage_status()]);
}


if ($action === 'push_public_key') {
    try { naa_respond(['ok'=>true,'publicKey'=>naa_push_public_key()]); }
    catch (Throwable $e) { naa_respond(['ok'=>false,'error'=>'Web Push indisponível: '.$e->getMessage()],503); }
}

/* Confirmação assinada pelo próprio payload criptografado do Web Push.
 * Permite registrar "entregue" mesmo com o index.html totalmente fechado. */
if ($action === 'push_receipt') {
    $messageId=trim((string)($in['messageId']??''));
    $userId=trim((string)($in['userId']??''));
    $receipt=trim((string)($in['receipt']??''));
    if(!naa_push_receipt_valid($messageId,$userId,$receipt)) naa_respond(['ok'=>false,'error'=>'Confirmação Push inválida.'],403);
    $messages=naa_read('messages');$changed=false;$sender='';$now=naa_now();
    foreach($messages as &$m){
        if((string)($m['id']??'')!==$messageId||(string)($m['to']??'')!==$userId)continue;
        $sender=(string)($m['from']??'');
        if(empty($m['deliveredAt'])){$m['deliveredAt']=$now;$changed=true;}
        break;
    }
    unset($m);
    if($changed){
        naa_write('messages',$messages);
        if($sender!=='') naa_add_event(['type'=>'message_status','userId'=>$sender,'messageId'=>$messageId,'status'=>'delivered']);
    }
    naa_respond(['ok'=>true,'changed'=>$changed]);
}


/* Ação de recusa iniciada diretamente pela notificação Push.
 * A confirmação usa token assinado e não depende da sessão HTML estar aberta. */
if ($action === 'direct_call_push_action') {
    $callId=trim((string)($in['callId']??''));
    $userId=trim((string)($in['userId']??''));
    $token=trim((string)($in['token']??''));
    $decision=trim((string)($in['decision']??''));
    if($callId===''||$userId===''||!naa_push_receipt_valid('call-action-'.$callId,$userId,$token)){
        naa_respond(['ok'=>false,'error'=>'Ação de chamada inválida.'],403);
    }
    $calls=naa_read('direct_calls');
    $idx=naa_call_find_index($calls,$callId);
    if($idx<0)naa_respond(['ok'=>false,'error'=>'Chamada não encontrada.'],404);
    $call=$calls[$idx];
    if((string)($call['to']??'')!==$userId)naa_respond(['ok'=>false,'error'=>'Destinatário inválido.'],403);
    if($decision==='declined'&&($call['status']??'')==='ringing'){
        $call=naa_call_finalize_record($calls,$idx,'declined',$userId);
        naa_write('direct_calls',$calls);
        naa_add_event(['type'=>'direct_call_status','userId'=>(string)$call['from'],'callId'=>$callId,'status'=>'declined']);
    } elseif($decision==='accepted'&&in_array((string)($call['status']??''),['ringing','accepted','connected'],true)){
        /* O botão Atender do Push confirma a intenção antes de o HTML terminar de abrir.
           Mantemos uma janela de handoff para o novo documento publicar o peerId. */
        if(($call['status']??'')==='ringing'){
            $call['status']='accepted';
            if(empty($call['acceptedAt']))$call['acceptedAt']=naa_now();
            naa_add_event(['type'=>'direct_call_status','userId'=>(string)$call['from'],'callId'=>$callId,'status'=>'accepted']);
        }
        $call['handoffUntil']=gmdate('c',time()+35);
        $calls[$idx]=$call;naa_write('direct_calls',$calls);
    }
    naa_respond(['ok'=>true,'call'=>naa_call_public($call,$userId)]);
}

if ($action === 'registration_meta') {
    $roles=[];
    foreach (['professor','coordenador','diretor','vice_diretor','secretaria_escolar','secretaria_educacao','administrativo','rede','pedagogico'] as $role) {
        $roles[]=['id'=>$role,'label'=>naa_role_label($role),'scope'=>(string)(naa_role_meta($role)['scope']??'global')];
    }
    naa_respond(['ok'=>true,'roles'=>$roles,'schools'=>naa_collect_schools()]);
}


if ($action === 'meet_invite_info') {
    $token=trim((string)($in['token']??''));
    if($token==='') naa_respond(['ok'=>false,'error'=>'Convite inválido.'],422);
    $invite=null;
    foreach(naa_read('meet_invites') as $x){
        if(hash_equals((string)($x['token']??''),$token) && ($x['status']??'open')==='open' && strtotime((string)($x['expiresAt']??''))>time()){
            $invite=$x;break;
        }
    }
    if(!$invite) naa_respond(['ok'=>false,'error'=>'Este convite expirou ou não é mais válido.'],404);
    $room=null;
    foreach(naa_read('rooms') as $r) if(($r['id']??'')===($invite['roomId']??'') && in_array(($r['status']??'open'),['open','scheduled'],true)){$room=$r;break;}
    if(!$room) naa_respond(['ok'=>false,'error'=>'A reunião vinculada a este convite foi encerrada.'],410);
    naa_respond(['ok'=>true,'invite'=>[
        'token'=>$token,'roomId'=>$room['id'],'roomTitle'=>$room['title']??'LS Meet',
        'scheduledAt'=>$room['scheduledAt']??'','inviterName'=>$invite['inviterName']??'NAA',
        'expiresAt'=>$invite['expiresAt']??'','certificate'=>!empty($room['certificate']),
        'targetParticipation'=>$room['targetParticipation']??''
    ]]);
}

if ($action === 'registration_submit') {
    $name=trim((string)($in['name']??''));
    $cpf=naa_digits((string)($in['cpf']??''));
    $email=naa_norm_email((string)($in['email']??''));
    $whatsapp=trim((string)($in['whatsapp']??''));
    $birthDate=trim((string)($in['birthDate']??''));
    $role=(string)($in['role']??'');
    $school=trim((string)($in['school']??''));
    $password=(string)($in['password']??'');
    $photo=(string)($in['photo']??'');
    $inviteToken=trim((string)($in['inviteToken']??''));
    $invite=null;

    if($inviteToken!==''){
        foreach(naa_read('meet_invites') as $x){
            if(hash_equals((string)($x['token']??''),$inviteToken) && ($x['status']??'open')==='open' && strtotime((string)($x['expiresAt']??''))>time()){$invite=$x;break;}
        }
        if(!$invite) naa_respond(['ok'=>false,'error'=>'O convite de reunião não é mais válido.'],410);
    }

    if ($name==='' || naa_len($name)<3) naa_respond(['ok'=>false,'error'=>'Informe seu nome completo.'],422);
    if (!naa_valid_cpf($cpf)) naa_respond(['ok'=>false,'error'=>'Informe um CPF válido.'],422);
    if (!filter_var($email,FILTER_VALIDATE_EMAIL)) naa_respond(['ok'=>false,'error'=>'Informe um e-mail válido.'],422);
    if (!naa_valid_whatsapp($whatsapp)) naa_respond(['ok'=>false,'error'=>'Informe um celular/WhatsApp válido com DDD.'],422);
    if (!naa_public_registration_role_allowed($role)) naa_respond(['ok'=>false,'error'=>'Cargo solicitado inválido.'],422);
    if ($invite!==null && $school==='') naa_respond(['ok'=>false,'error'=>'Para entrar como convidado, informe a escola à qual você pertence.'],422);
    if ($invite===null && (naa_role_meta($role)['scope']??'global')==='school' && $school==='') naa_respond(['ok'=>false,'error'=>'Selecione a escola à qual você está vinculado.'],422);
    if (strlen($password)<6) naa_respond(['ok'=>false,'error'=>'A senha precisa ter pelo menos 6 caracteres.'],422);
    if (!naa_valid_photo_data($photo)) naa_respond(['ok'=>false,'error'=>'A foto é obrigatória e deve ser uma imagem válida.'],422);

    foreach (naa_read('users') as $u) {
        if ((string)($u['cpf']??'')===$cpf || naa_norm_email((string)($u['email']??''))===$email) {
            naa_respond(['ok'=>false,'error'=>'Já existe um acesso cadastrado com este CPF ou e-mail.'],409);
        }
    }

    $requests=naa_read('registration_requests');
    foreach ($requests as $r) {
        if (($r['status']??'')==='pending' && ((string)($r['cpf']??'')===$cpf || naa_norm_email((string)($r['email']??''))===$email)) {
            naa_respond(['ok'=>false,'error'=>'Já existe uma solicitação aguardando análise para este CPF ou e-mail.','protocol'=>$r['protocol']??''],409);
        }
    }

    $protocol='NAA-'.gmdate('Ymd').'-'.strtoupper(substr(bin2hex(random_bytes(5)),0,8));
    $req=[
        'id'=>naa_id('req'),'protocol'=>$protocol,'status'=>'pending','name'=>$name,'cpf'=>$cpf,'email'=>$email,
        'whatsapp'=>$whatsapp,'birthDate'=>$birthDate,'role'=>$role,
        'school'=>$invite!==null?$school:(((naa_role_meta($role)['scope']??'global')==='school')?$school:''),
        'photo'=>$photo,'passwordHash'=>password_hash($password,PASSWORD_DEFAULT),
        'inviteToken'=>$inviteToken,'inviteRoomId'=>$invite['roomId']??'','inviteBy'=>$invite['inviterId']??'',
        'createdAt'=>naa_now(),'updatedAt'=>naa_now(),'approvedAt'=>'','approvedBy'=>'','userId'=>'','rejectionReason'=>''
    ];
    $requests[]=$req;
    if (count($requests)>5000) $requests=array_slice($requests,-4000);
    naa_write('registration_requests',$requests);
    if($invite){
        $waiting=naa_read('meet_waiting');
        $waiting[]=[
            'id'=>naa_id('wait'),'roomId'=>$invite['roomId'],'type'=>'registration','registrationId'=>$req['id'],
            'name'=>$name,'photo'=>$photo,'role'=>$role,'school'=>$school,'requestedBy'=>$invite['inviterId']??'','status'=>'waiting','createdAt'=>naa_now()
        ];
        naa_write_required('meet_waiting',$waiting);
        naa_add_event(['type'=>'meet_waiting','userId'=>$invite['inviterId']??'','title'=>'Convidado aguardando liberação','body'=>$name.' concluiu o cadastro para entrar na reunião.','roomId'=>$invite['roomId']??'']);
    } else {
        naa_add_event(['type'=>'registration_request','title'=>'Nova solicitação de acesso','body'=>$name.' solicitou acesso ao NAA.','adminOnly'=>true]);
    }
    naa_respond(['ok'=>true,'protocol'=>$protocol,'status'=>'pending','inviteRoomId'=>$invite['roomId']??'']);
}

if ($action === 'registration_status') {
    $protocol=trim((string)($in['protocol']??''));
    $cpf=naa_digits((string)($in['cpf']??''));
    if ($protocol==='' || strlen($cpf)!==11) naa_respond(['ok'=>false,'error'=>'Informe protocolo e CPF.'],422);
    foreach (naa_read('registration_requests') as $r) {
        if (strcasecmp((string)($r['protocol']??''),$protocol)===0 && (string)($r['cpf']??'')===$cpf) {
            naa_respond(['ok'=>true,'request'=>naa_registration_public($r,false)]);
        }
    }
    naa_respond(['ok'=>false,'error'=>'Solicitação não encontrada.'],404);
}

if ($action === 'bootstrap') {
    $users=naa_read('users');
    if ($users) naa_respond(['ok'=>false,'error'=>'A configuração inicial já foi concluída.'],409);
    $name=trim((string)($in['name']??''));
    $cpf=naa_digits((string)($in['cpf']??''));
    $email=naa_norm_email((string)($in['email']??''));
    $password=(string)($in['password']??'');
    $photo=(string)($in['photo']??'');
    if ($name===''||!naa_valid_cpf($cpf)||!filter_var($email,FILTER_VALIDATE_EMAIL)||!naa_valid_whatsapp((string)($in['whatsapp']??''))||strlen($password)<6||!naa_valid_photo_data($photo)) {
        naa_respond(['ok'=>false,'error'=>'Preencha nome, foto obrigatória, CPF, e-mail válido e senha com pelo menos 6 caracteres.'],422);
    }
    $u=[
        'id'=>naa_id('usr'),'name'=>$name,'cpf'=>$cpf,'email'=>$email,'whatsapp'=>trim((string)($in['whatsapp']??'')),
        'birthDate'=>trim((string)($in['birthDate']??'')),'role'=>'ceo_programador','school'=>'','photo'=>$photo,
        'allowUpload'=>true,'allowMeetCreate'=>true,'active'=>true,'passwordHash'=>password_hash($password,PASSWORD_DEFAULT),
        'forcePasswordChange'=>$password==='123456','createdAt'=>naa_now(),'updatedAt'=>naa_now(),'lastLogin'=>naa_now()
    ];
    naa_write('users',[$u]);
    $token=naa_session_create($u,!empty($in['persistent']));
    naa_respond(['ok'=>true,'user'=>naa_public_user($u,true),'token'=>$token,'firstLogin'=>true,'forcePasswordChange'=>!empty($u['forcePasswordChange'])]);
}

if ($action === 'login') {
    $identifier=trim((string)($in['identifier']??''));
    $digits=naa_digits($identifier);
    $email=naa_norm_email($identifier);
    $password=(string)($in['password']??'');
    $users=naa_read('users');
    $idx=null;
    foreach($users as $i=>$u){
        if (($u['active']??true)===false) continue;
        if (($digits!==''&&$digits===(string)($u['cpf']??''))||($email!==''&&$email===naa_norm_email((string)($u['email']??'')))){$idx=$i;break;}
    }
    if ($idx===null) {
        foreach(naa_read('registration_requests') as $r){
            $match=($digits!==''&&$digits===(string)($r['cpf']??''))||($email!==''&&$email===naa_norm_email((string)($r['email']??'')));
            if(!$match)continue;
            if(password_verify($password,(string)($r['passwordHash']??''))){
                if(($r['status']??'')==='pending') naa_respond(['ok'=>false,'error'=>'Seu cadastro foi recebido e ainda aguarda liberação do CEO Programador ou Núcleo (NAA).','pending'=>true,'protocol'=>$r['protocol']??''],403);
                if(($r['status']??'')==='rejected') naa_respond(['ok'=>false,'error'=>'Sua solicitação não foi liberada. '.trim((string)($r['rejectionReason']??'')),'rejected'=>true],403);
            }
        }
        naa_respond(['ok'=>false,'error'=>'CPF/e-mail ou senha incorretos.'],401);
    }
    if (!password_verify($password,(string)($users[$idx]['passwordHash']??''))) naa_respond(['ok'=>false,'error'=>'CPF/e-mail ou senha incorretos.'],401);
    $first=empty($users[$idx]['lastLogin']);
    $users[$idx]['lastLogin']=naa_now();$users[$idx]['updatedAt']=naa_now();naa_write('users',$users);
    $token=naa_session_create($users[$idx],!empty($in['persistent']));
    naa_respond(['ok'=>true,'user'=>naa_public_user($users[$idx],true),'token'=>$token,'firstLogin'=>$first,'forcePasswordChange'=>!empty($users[$idx]['forcePasswordChange'])]);
}

/* =========================================================
 * ROTAS AUTENTICADAS
 * ========================================================= */
$me=naa_auth($in);

if ($action==='me') naa_respond(['ok'=>true,'user'=>naa_public_user($me,true)]);

if ($action==='global_settings_update') {
    if (($me['role']??'')!=='ceo_programador') naa_respond(['ok'=>false,'error'=>'Somente o CEO Programador pode alterar configurações globais.'],403);
    $cfg=is_array($in['settings']??null)?$in['settings']:[];
    $allowed=['theme','radius','primary','accent','navy','fontScale','density','animations','shadows','logoPrefeitura','logoNucleo','logoIcon','secondary','glassOpacity','maxContentWidth','sidebarSize','showFooter','showAngela','showInstallPrompt','systemName','systemSubtitle','footerText','moduleRanking','moduleStudents','moduleMessages','moduleMeet','moduleUpload','moduleInfo','meetDefaultMaxMedia','meetDefaultLocked','meetDefaultCertificate','meetDefaultTranscript','dashboardDefault','maintenanceMode','maintenanceMessage'];
    $clean=[]; foreach($allowed as $k) if(array_key_exists($k,$cfg)) $clean[$k]=$cfg[$k];
    if(isset($clean['radius']))$clean['radius']=max(0,min(50,(int)$clean['radius']));
    if(isset($clean['fontScale']))$clean['fontScale']=max(.75,min(1.35,(float)$clean['fontScale']));
    if(isset($clean['meetDefaultMaxMedia']))$clean['meetDefaultMaxMedia']=max(1,(int)$clean['meetDefaultMaxMedia']);
    if(isset($clean['maxContentWidth']))$clean['maxContentWidth']=max(0,(int)$clean['maxContentWidth']);
    $clean['updatedAt']=naa_now();$clean['updatedBy']=$me['id'];
    naa_write_required('global_settings',$clean);
    naa_add_event(['type'=>'global_settings','title'=>'Configurações da plataforma atualizadas','body'=>'O CEO atualizou a experiência global do NAA.']);
    naa_respond(['ok'=>true,'settings'=>$clean]);
}


if ($action==='push_subscribe') {
    $subscription=is_array($in['subscription']??null)?$in['subscription']:[];
    try {
        $saved=naa_push_store_subscription((string)$me['id'],$subscription,(string)($_SERVER['HTTP_USER_AGENT']??''));
        naa_respond(['ok'=>true,'subscription'=>$saved]);
    } catch(Throwable $e) { naa_respond(['ok'=>false,'error'=>$e->getMessage()],422); }
}

if ($action==='push_unsubscribe') {
    $endpoint=trim((string)($in['endpoint']??''));
    naa_respond(['ok'=>true,'removed'=>naa_push_remove_subscription((string)$me['id'],$endpoint)]);
}

if ($action==='change_password') {
    $current=(string)($in['current']??'');$next=(string)($in['next']??'');
    if(strlen($next)<6)naa_respond(['ok'=>false,'error'=>'A nova senha precisa ter pelo menos 6 caracteres.'],422);
    $users=naa_read('users');
    foreach($users as &$u){
        if(($u['id']??'')!==($me['id']??''))continue;
        if(!password_verify($current,(string)($u['passwordHash']??'')))naa_respond(['ok'=>false,'error'=>'Senha atual incorreta.'],401);
        $u['passwordHash']=password_hash($next,PASSWORD_DEFAULT);$u['forcePasswordChange']=false;$u['updatedAt']=naa_now();$me=$u;break;
    }
    unset($u);naa_write('users',$users);naa_respond(['ok'=>true,'user'=>naa_public_user($me,true)]);
}

if ($action==='users_list') {
    $full=naa_is_admin($me);$out=[];$presence=naa_read('presence');
    foreach(naa_read('users') as $u){
        // Administradores precisam enxergar também os acessos desativados para poder reativá-los.
        if((($u['active']??true)===false && !$full)||!naa_can_see_user($me,$u))continue;
        $x=naa_public_user($u,$full);
        $x['lastSeen']=(string)($presence[$u['id']]['lastSeen']??'');
        $out[]=$x;
    }
    usort($out,fn($a,$b)=>strnatcasecmp((string)$a['name'],(string)$b['name']));
    naa_respond(['ok'=>true,'users'=>$out]);
}

if ($action==='user_upsert') {
    if(!naa_is_admin($me))naa_respond(['ok'=>false,'error'=>'Sem permissão para administrar usuários.'],403);
    $x=$in['user']??null;if(!is_array($x))naa_respond(['ok'=>false,'error'=>'Usuário inválido.'],422);
    $id=trim((string)($x['id']??''));$name=trim((string)($x['name']??''));$cpf=naa_digits((string)($x['cpf']??''));
    $email=naa_norm_email((string)($x['email']??''));$role=(string)($x['role']??'');$school=trim((string)($x['school']??''));$password=(string)($x['password']??'');$photo=(string)($x['photo']??'');
    if($name===''||!naa_valid_cpf($cpf)||!filter_var($email,FILTER_VALIDATE_EMAIL)||!naa_valid_whatsapp((string)($x['whatsapp']??''))||!naa_role_allowed($role))naa_respond(['ok'=>false,'error'=>'Nome, CPF, e-mail ou cargo inválido.'],422);
    if((naa_role_meta($role)['scope']??'global')==='school'&&$school==='')naa_respond(['ok'=>false,'error'=>'Este cargo precisa estar vinculado a uma escola.'],422);
    $users=naa_read('users');
    foreach($users as $u){if(($u['id']??'')!==$id&&((string)($u['cpf']??'')===$cpf||naa_norm_email((string)($u['email']??''))===$email))naa_respond(['ok'=>false,'error'=>'Já existe usuário com este CPF ou e-mail.'],409);}
    $found=false;
    foreach($users as &$u){
        if(($u['id']??'')!==$id)continue;$found=true;
        $nextPhoto=$photo!==''?$photo:(string)($u['photo']??'');
        if(!naa_valid_photo_data($nextPhoto))naa_respond(['ok'=>false,'error'=>'A foto do usuário é obrigatória.'],422);
        $u=array_merge($u,['name'=>$name,'cpf'=>$cpf,'email'=>$email,'whatsapp'=>trim((string)($x['whatsapp']??'')),'birthDate'=>trim((string)($x['birthDate']??'')),'role'=>$role,'school'=>(naa_role_meta($role)['scope']??'global')==='school'?$school:'','allowUpload'=>!empty($x['allowUpload']),'allowMeetCreate'=>!empty($x['allowMeetCreate']),'permissions'=>is_array($x['permissions']??null)?$x['permissions']:($u['permissions']??[]),'photo'=>$nextPhoto,'updatedAt'=>naa_now()]);
        if($password!==''){$u['passwordHash']=password_hash($password,PASSWORD_DEFAULT);$u['forcePasswordChange']=$password==='123456';}
        break;
    }
    unset($u);
    if(!$found){
        if(strlen($password)<6)naa_respond(['ok'=>false,'error'=>'Informe uma senha com pelo menos 6 caracteres.'],422);
        if(!naa_valid_photo_data($photo))naa_respond(['ok'=>false,'error'=>'A foto é obrigatória para novos usuários.'],422);
        $users[]=['id'=>naa_id('usr'),'name'=>$name,'cpf'=>$cpf,'email'=>$email,'whatsapp'=>trim((string)($x['whatsapp']??'')),'birthDate'=>trim((string)($x['birthDate']??'')),'role'=>$role,'school'=>(naa_role_meta($role)['scope']??'global')==='school'?$school:'','allowUpload'=>!empty($x['allowUpload']),'allowMeetCreate'=>!empty($x['allowMeetCreate']),'permissions'=>is_array($x['permissions']??null)?$x['permissions']:[],'photo'=>$photo,'active'=>true,'passwordHash'=>password_hash($password,PASSWORD_DEFAULT),'forcePasswordChange'=>$password==='123456','createdAt'=>naa_now(),'updatedAt'=>naa_now(),'lastLogin'=>''];
    }
    naa_write('users',$users);if($id!=='')naa_add_event(['type'=>'profile','userId'=>$id,'title'=>'Permissões atualizadas']);naa_respond(['ok'=>true]);
}

if ($action==='user_delete') {
    if(!naa_is_admin($me))naa_respond(['ok'=>false,'error'=>'Sem permissão.'],403);
    $id=(string)($in['id']??'');if($id===$me['id'])naa_respond(['ok'=>false,'error'=>'Você não pode excluir o próprio acesso.'],422);
    $users=naa_read('users');foreach($users as &$u)if(($u['id']??'')===$id){$u['active']=false;$u['updatedAt']=naa_now();}unset($u);naa_write('users',$users);naa_respond(['ok'=>true]);
}

if ($action==='user_set_active') {
    if(!naa_is_admin($me))naa_respond(['ok'=>false,'error'=>'Sem permissão.'],403);
    $id=trim((string)($in['id']??''));
    $raw=$in['active']??false;
    $active=is_bool($raw)?$raw:in_array(strtolower(trim((string)$raw)),['1','true','yes','on','ativo','active'],true);
    if($id==='')naa_respond(['ok'=>false,'error'=>'Usuário inválido.'],422);
    if($id===$me['id']&&!$active)naa_respond(['ok'=>false,'error'=>'Você não pode desativar o próprio acesso.'],422);
    $found=false;$users=naa_read('users');
    foreach($users as &$u){
        if((string)($u['id']??'')!==$id)continue;
        $u['active']=$active;
        $u['status']=$active?'ativo':'desativado';
        $u['updatedAt']=naa_now();
        $found=true;break;
    }
    unset($u);
    if(!$found)naa_respond(['ok'=>false,'error'=>'Usuário não encontrado.'],404);
    naa_write('users',$users);
    naa_add_event(['type'=>'profile','userId'=>$id,'title'=>$active?'Acesso reativado':'Acesso desativado']);
    $updated=null;foreach($users as $u){if((string)($u['id']??'')===$id){$updated=naa_public_user($u,true);$updated['active']=$active;break;}}
    naa_respond(['ok'=>true,'active'=>$active,'user'=>$updated]);
}

if ($action==='user_activate') {
    if(!naa_is_admin($me))naa_respond(['ok'=>false,'error'=>'Sem permissão.'],403);
    $id=(string)($in['id']??'');
    $found=false;$users=naa_read('users');
    foreach($users as &$u){
        if(($u['id']??'')!==$id)continue;
        $u['active']=true;$u['updatedAt']=naa_now();$found=true;break;
    }
    unset($u);
    if(!$found)naa_respond(['ok'=>false,'error'=>'Usuário não encontrado.'],404);
    naa_write('users',$users);
    naa_add_event(['type'=>'profile','userId'=>$id,'title'=>'Acesso reativado']);
    naa_respond(['ok'=>true]);
}

/* =========================================================
 * SOLICITAÇÕES DE ACESSO
 * ========================================================= */
if ($action==='registrations_list') {
    if(!naa_is_admin($me))naa_respond(['ok'=>false,'error'=>'Sem permissão.'],403);
    $list=array_map(fn($r)=>naa_registration_public($r,true),naa_read('registration_requests'));
    usort($list,fn($a,$b)=>strcmp((string)$b['createdAt'],(string)$a['createdAt']));
    naa_respond(['ok'=>true,'requests'=>$list]);
}

if ($action==='registration_approve') {
    if(!naa_is_admin($me))naa_respond(['ok'=>false,'error'=>'Sem permissão.'],403);
    $id=(string)($in['id']??'');$role=(string)($in['role']??'');$school=trim((string)($in['school']??''));
    if(!naa_role_allowed($role)||in_array($role,['ceo_programador','nucleo_naa'],true))naa_respond(['ok'=>false,'error'=>'Cargo de aprovação inválido.'],422);
    if((naa_role_meta($role)['scope']??'global')==='school'&&$school==='')naa_respond(['ok'=>false,'error'=>'Selecione a escola vinculada.'],422);
    $requests=naa_read('registration_requests');$reqIndex=null;
    foreach($requests as $i=>$r)if(($r['id']??'')===$id){$reqIndex=$i;break;}
    if($reqIndex===null)naa_respond(['ok'=>false,'error'=>'Solicitação não encontrada.'],404);
    $r=$requests[$reqIndex];if(($r['status']??'')!=='pending')naa_respond(['ok'=>false,'error'=>'Esta solicitação já foi analisada.'],409);
    $users=naa_read('users');
    foreach($users as $u){if((string)($u['cpf']??'')===(string)$r['cpf']||naa_norm_email((string)($u['email']??''))===naa_norm_email((string)$r['email']))naa_respond(['ok'=>false,'error'=>'Já existe usuário com este CPF ou e-mail.'],409);}
    $user=[
        'id'=>naa_id('usr'),'name'=>$r['name'],'cpf'=>$r['cpf'],'email'=>$r['email'],'whatsapp'=>$r['whatsapp']??'',
        'birthDate'=>$r['birthDate']??'','role'=>$role,'school'=>(naa_role_meta($role)['scope']??'global')==='school'?$school:'',
        'photo'=>$r['photo'],'allowUpload'=>!empty($in['allowUpload']),'allowMeetCreate'=>!empty($in['allowMeetCreate']),'active'=>true,
        'passwordHash'=>$r['passwordHash'],'forcePasswordChange'=>false,'createdAt'=>naa_now(),'updatedAt'=>naa_now(),'lastLogin'=>''
    ];
    $users[]=$user;naa_write('users',$users);
    $requests[$reqIndex]['status']='approved';$requests[$reqIndex]['role']=$role;$requests[$reqIndex]['school']=$user['school'];
    $requests[$reqIndex]['approvedAt']=naa_now();$requests[$reqIndex]['approvedBy']=$me['id'];$requests[$reqIndex]['userId']=$user['id'];$requests[$reqIndex]['updatedAt']=naa_now();naa_write('registration_requests',$requests);
    naa_add_event(['type'=>'access_approved','userId'=>$user['id'],'title'=>'Acesso liberado','body'=>'Seu acesso ao NAA foi aprovado.']);
    naa_respond(['ok'=>true,'user'=>naa_public_user($user,true)]);
}

if ($action==='registration_reject') {
    if(!naa_is_admin($me))naa_respond(['ok'=>false,'error'=>'Sem permissão.'],403);
    $id=(string)($in['id']??'');$reason=trim((string)($in['reason']??''));$requests=naa_read('registration_requests');$found=false;
    foreach($requests as &$r){if(($r['id']??'')!==$id)continue;if(($r['status']??'')!=='pending')naa_respond(['ok'=>false,'error'=>'Esta solicitação já foi analisada.'],409);$r['status']='rejected';$r['rejectionReason']=$reason;$r['updatedAt']=naa_now();$r['approvedBy']=$me['id'];$found=true;break;}unset($r);
    if(!$found)naa_respond(['ok'=>false,'error'=>'Solicitação não encontrada.'],404);naa_write('registration_requests',$requests);naa_respond(['ok'=>true]);
}

if ($action==='registration_delete') {
    if(!naa_is_admin($me))naa_respond(['ok'=>false,'error'=>'Sem permissão.'],403);
    $id=(string)($in['id']??'');$requests=array_values(array_filter(naa_read('registration_requests'),fn($r)=>(string)($r['id']??'')!==$id));naa_write('registration_requests',$requests);naa_respond(['ok'=>true]);
}

/* =========================================================
 * PRESENÇA
 * ========================================================= */
if ($action==='presence_ping') {
    $presence=naa_read('presence');
    $prev=is_array($presence[$me['id']]??null)?$presence[$me['id']]:[];
    $presence[$me['id']]=array_merge($prev,['lastSeen'=>naa_now(),'role'=>$me['role']??'','school'=>$me['school']??'']);
    naa_write('presence',$presence);naa_respond(['ok'=>true,'lastSeen'=>$presence[$me['id']]['lastSeen']]);
}
if ($action==='presence_list') {
    $presence=naa_read('presence');$out=[];foreach(naa_read('users') as $u){if(($u['active']??true)===false||!naa_can_see_user($me,$u))continue;$out[$u['id']]=(string)($presence[$u['id']]['lastSeen']??'');}naa_respond(['ok'=>true,'presence'=>$out]);
}
if ($action==='peer_update') {
    $peerId=preg_replace('/[^a-zA-Z0-9_-]/','',(string)($in['peerId']??''));
    $presence=naa_read('presence');$prev=is_array($presence[$me['id']]??null)?$presence[$me['id']]:[];
    $presence[$me['id']]=array_merge($prev,['lastSeen'=>naa_now(),'role'=>$me['role']??'','school'=>$me['school']??'','peerId'=>$peerId,'peerUpdatedAt'=>naa_now()]);
    naa_write('presence',$presence);naa_respond(['ok'=>true,'peerId'=>$peerId]);
}
if ($action==='peer_get') {
    $userId=(string)($in['userId']??'');$target=naa_find_user($userId);
    if(!$target||!naa_can_see_user($me,$target))naa_respond(['ok'=>false,'error'=>'Contato indisponível.'],404);
    $presence=naa_read('presence');$p=is_array($presence[$userId]??null)?$presence[$userId]:[];
    $last=strtotime((string)($p['peerUpdatedAt']??$p['lastSeen']??''))?:0;
    $fresh=$last>0&&(time()-$last)<90;
    naa_respond(['ok'=>true,'peerId'=>$fresh?(string)($p['peerId']??''):'','lastSeen'=>(string)($p['lastSeen']??'')]);
}

if ($action==='direct_call_start') {
    $to=(string)($in['to']??'');
    $mode=(string)($in['mode']??'voice')==='video'?'video':'voice';
    $target=naa_find_user($to);
    if(!$target||($target['active']??true)===false||!naa_can_see_user($me,$target))naa_respond(['ok'=>false,'error'=>'Contato indisponível.'],404);
    if($to===(string)$me['id'])naa_respond(['ok'=>false,'error'=>'Não é possível ligar para si mesmo.'],422);

    $calls=naa_read('direct_calls');
    $now=naa_now();

    /* Fecha automaticamente tentativas antigas que ficaram presas em "tocando". */
    foreach($calls as $i=>$c){
        if(($c['status']??'')!=='ringing')continue;
        $created=strtotime((string)($c['createdAt']??''))?:0;
        if($created>0&&(time()-$created)>75){
            naa_call_finalize_record($calls,(int)$i,'missed','system');
        }
    }

    $callerBusy=false;$targetBusy=false;
    foreach($calls as $c){
        if(!naa_call_is_active_status((string)($c['status']??'')))continue;
        $a=(string)($c['from']??'');$b=(string)($c['to']??'');
        if($a===(string)$me['id']||$b===(string)$me['id'])$callerBusy=true;
        if($a===$to||$b===$to)$targetBusy=true;
    }
    if($callerBusy){
        naa_write('direct_calls',$calls);
        naa_respond(['ok'=>false,'error'=>'Você já está em outra chamada.','busy'=>true],409);
    }

    $call=[
        'id'=>naa_id('call'),
        'from'=>(string)$me['id'],
        'to'=>$to,
        'fromName'=>(string)($me['name']??''),
        'toName'=>(string)($target['name']??''),
        'mode'=>$mode,
        'status'=>$targetBusy?'busy':'ringing',
        'createdAt'=>$now,
        'acceptedAt'=>'',
        'connectedAt'=>'',
        'endedAt'=>'',
        'endedBy'=>'',
        'durationSeconds'=>0,
        'media'=>[
            (string)$me['id']=>['mic'=>true,'camera'=>$mode==='video','updatedAt'=>$now],
            $to=>['mic'=>true,'camera'=>$mode==='video','updatedAt'=>$now]
        ],
        'historyMessageId'=>''
    ];
    $calls[]=$call;$idx=count($calls)-1;
    if($targetBusy){
        $call=naa_call_finalize_record($calls,$idx,'busy','system');
        naa_write('direct_calls',$calls);
        naa_respond(['ok'=>true,'busy'=>true,'call'=>naa_call_public($call,(string)$me['id'])]);
    }

    naa_write('direct_calls',$calls);
    $actionToken=naa_push_receipt_token('call-action-'.$call['id'],$to);
    naa_add_event([
        'type'=>'direct_call',
        'id'=>'evt_'.$call['id'],
        'callId'=>$call['id'],
        'userId'=>$to,
        'receiptUserId'=>$to,
        'callActionToken'=>$actionToken,
        'fromId'=>$me['id'],
        'fromName'=>$me['name'],
        'senderPhoto'=>$me['photo']??'',
        'mode'=>$mode,
        'title'=>$mode==='video'?'Chamada de vídeo':'Chamada de voz',
        'body'=>$me['name'].' está chamando você',
        'url'=>'./index.html?open=messages&peer='.rawurlencode((string)$me['id']).'&call='.$mode.'&callId='.rawurlencode((string)$call['id'])
    ]);
    naa_respond(['ok'=>true,'busy'=>false,'call'=>naa_call_public($call,(string)$me['id'])]);
}

if ($action==='direct_call_status') {
    $callId=(string)($in['callId']??'');
    $calls=naa_read('direct_calls');$idx=naa_call_find_index($calls,$callId);
    if($idx<0)naa_respond(['ok'=>false,'error'=>'Chamada não encontrada.'],404);
    $call=$calls[$idx];
    $uid=(string)$me['id'];
    if(!in_array($uid,[(string)($call['from']??''),(string)($call['to']??'')],true))naa_respond(['ok'=>false,'error'=>'Sem acesso a esta chamada.'],403);

    if(($call['status']??'')==='ringing'){
        $created=strtotime((string)($call['createdAt']??''))?:0;
        if($created>0&&(time()-$created)>75){
            $call=naa_call_finalize_record($calls,$idx,'missed','system');
            naa_write('direct_calls',$calls);
            $other=$uid===(string)$call['from']?(string)$call['to']:(string)$call['from'];
            naa_add_event(['type'=>'direct_call_status','userId'=>$other,'callId'=>$callId,'status'=>'missed']);
        }
    } elseif(($call['status']??'')==='accepted'&&empty($call['connectedAt'])){
        $until=strtotime((string)($call['handoffUntil']??''))?:0;
        if($until>0&&time()>$until){
            $call=naa_call_finalize_record($calls,$idx,'failed','system');
            naa_write('direct_calls',$calls);
            $other=$uid===(string)$call['from']?(string)$call['to']:(string)$call['from'];
            naa_add_event(['type'=>'direct_call_status','userId'=>$other,'callId'=>$callId,'status'=>'failed']);
        }
    }

    $otherId=$uid===(string)($call['from']??'')?(string)($call['to']??''):(string)($call['from']??'');
    $presence=naa_read('presence');$pr=is_array($presence[$otherId]??null)?$presence[$otherId]:[];
    $last=strtotime((string)($pr['peerUpdatedAt']??$pr['lastSeen']??''))?:0;
    $otherPeerId=($last>0&&(time()-$last)<90)?(string)($pr['peerId']??''):'';
    naa_respond(['ok'=>true,'call'=>naa_call_public($call,$uid),'otherPeerId'=>$otherPeerId]);
}

if ($action==='direct_call_action') {
    $callId=(string)($in['callId']??'');
    $op=(string)($in['op']??'');
    $calls=naa_read('direct_calls');$idx=naa_call_find_index($calls,$callId);
    if($idx<0)naa_respond(['ok'=>false,'error'=>'Chamada não encontrada.'],404);
    $call=$calls[$idx];
    $uid=(string)$me['id'];$from=(string)($call['from']??'');$to=(string)($call['to']??'');
    if(!in_array($uid,[$from,$to],true))naa_respond(['ok'=>false,'error'=>'Sem acesso a esta chamada.'],403);
    $other=$uid===$from?$to:$from;
    $now=naa_now();

    if($op==='accept'){
        if($uid!==$to)naa_respond(['ok'=>false,'error'=>'Somente o destinatário pode atender.'],403);
        $status=(string)($call['status']??'');
        if(!in_array($status,['ringing','accepted','connected'],true))naa_respond(['ok'=>false,'error'=>'Esta chamada já foi encerrada.','call'=>naa_call_public($call,$uid)],409);
        $peerId=preg_replace('/[^a-zA-Z0-9_-]/','',(string)($in['peerId']??''));
        if($peerId!==''){
            $presence=naa_read('presence');$prev=is_array($presence[$uid]??null)?$presence[$uid]:[];
            $presence[$uid]=array_merge($prev,['lastSeen'=>$now,'peerId'=>$peerId,'peerUpdatedAt'=>$now]);
            naa_write('presence',$presence);
        }
        if($status==='ringing'){
            $call['status']='accepted';if(empty($call['acceptedAt']))$call['acceptedAt']=$now;
            naa_add_event(['type'=>'direct_call_status','userId'=>$other,'callId'=>$callId,'status'=>'accepted']);
        }
        if($call['status']!=='connected')$call['handoffUntil']=gmdate('c',time()+35);
        $calls[$idx]=$call;naa_write('direct_calls',$calls);
        naa_respond(['ok'=>true,'call'=>naa_call_public($call,$uid)]);
    }

    if($op==='connected'){
        if(in_array((string)($call['status']??''),['accepted','connected'],true)){
            if(empty($call['connectedAt']))$call['connectedAt']=$now;
            $call['status']='connected';$call['handoffUntil']='';$calls[$idx]=$call;naa_write('direct_calls',$calls);
            naa_add_event(['type'=>'direct_call_status','userId'=>$other,'callId'=>$callId,'status'=>'connected']);
        }
        naa_respond(['ok'=>true,'call'=>naa_call_public($call,$uid)]);
    }

    if($op==='media'){
        $media=is_array($call['media']??null)?$call['media']:[];
        $prev=is_array($media[$uid]??null)?$media[$uid]:[];
        if(array_key_exists('camera',$in))$prev['camera']=(bool)$in['camera'];
        if(array_key_exists('mic',$in))$prev['mic']=(bool)$in['mic'];
        $prev['updatedAt']=$now;$media[$uid]=$prev;$call['media']=$media;$calls[$idx]=$call;naa_write('direct_calls',$calls);
        naa_add_event(['type'=>'direct_call_status','userId'=>$other,'callId'=>$callId,'status'=>'media']);
        naa_respond(['ok'=>true,'call'=>naa_call_public($call,$uid)]);
    }

    $terminal='';
    if($op==='decline'&&$uid===$to)$terminal='declined';
    elseif($op==='busy'&&$uid===$to)$terminal='busy';
    elseif($op==='missed')$terminal='missed';
    elseif($op==='cancel'&&$uid===$from)$terminal='cancelled';
    elseif($op==='interrupted')$terminal='interrupted';
    elseif($op==='failed')$terminal='failed';
    elseif($op==='end'){
        $terminal=in_array((string)($call['status']??''),['accepted','connected'],true)?'ended':($uid===$from?'cancelled':'declined');
    }
    if($terminal!==''){
        if(!naa_call_is_terminal_status((string)($call['status']??''))){
            $call=naa_call_finalize_record($calls,$idx,$terminal,$uid);
            naa_write('direct_calls',$calls);
            naa_add_event(['type'=>'direct_call_status','userId'=>$other,'callId'=>$callId,'status'=>$terminal,'historyMessageId'=>$call['historyMessageId']??'']);
        }
        naa_respond(['ok'=>true,'call'=>naa_call_public($call,$uid)]);
    }

    naa_respond(['ok'=>false,'error'=>'Ação de chamada inválida.'],422);
}

if ($action==='direct_call_notify') {
    $to=(string)($in['to']??'');$mode=(string)($in['mode']??'voice')==='video'?'video':'voice';$target=naa_find_user($to);
    if(!$target||!naa_can_see_user($me,$target))naa_respond(['ok'=>false,'error'=>'Contato indisponível.'],404);
    $eventId=naa_id('call');
    naa_add_event(['type'=>'direct_call','id'=>$eventId,'userId'=>$to,'fromId'=>$me['id'],'fromName'=>$me['name'],'senderPhoto'=>$me['photo']??'','mode'=>$mode,'title'=>$mode==='video'?'Chamada de vídeo':'Chamada de voz','body'=>$me['name'].' está chamando você','url'=>'./index.html?open=messages&peer='.rawurlencode((string)$me['id']).'&call='.$mode]);
    naa_respond(['ok'=>true,'eventId'=>$eventId]);
}

/* =========================================================
 * MENSAGENS
 * ========================================================= */
if ($action==='messages_list') {
    $uid=(string)$me['id'];$messages=naa_read('messages');
    $list=array_values(array_filter($messages,function($m) use ($uid){
        if(!((string)($m['from']??'')===$uid||(string)($m['to']??'')===$uid))return false;
        $deletedFor=is_array($m['deletedFor']??null)?$m['deletedFor']:[];
        return !in_array($uid,array_map('strval',$deletedFor),true);
    }));
    if(count($list)>2000)$list=array_slice($list,-2000);
    $unreadTotal=0;$unreadByPeer=[];
    foreach($list as $m){
        if((string)($m['to']??'')!==$uid||!empty($m['readAt']))continue;
        $unreadTotal++;$from=(string)($m['from']??'');$unreadByPeer[$from]=($unreadByPeer[$from]??0)+1;
    }
    naa_respond(['ok'=>true,'messages'=>$list,'unreadTotal'=>$unreadTotal,'unreadByPeer'=>$unreadByPeer]);
}

if ($action==='message_send') {
    $to=(string)($in['to']??'');$text=trim((string)($in['text']??''));$kind=preg_replace('/[^a-z0-9_-]/i','',(string)($in['kind']??'text')) ?: 'text';
    $target=naa_find_user($to);if(!$target||($target['active']??true)===false||!naa_can_see_user($me,$target))naa_respond(['ok'=>false,'error'=>'Destinatário não encontrado ou fora da sua área de comunicação.'],404);
    $attachment=is_array($in['attachment']??null)?$in['attachment']:null;$meta=is_array($in['meta']??null)?$in['meta']:[];$replyTo=trim((string)($in['replyTo']??''));
    if($text===''&&!$attachment&&$kind!=='location')naa_respond(['ok'=>false,'error'=>'Mensagem vazia.'],422);if(naa_len($text)>4000)naa_respond(['ok'=>false,'error'=>'Mensagem muito longa.'],422);
    $reply=null;if($replyTo!==''){foreach(naa_read('messages') as $old){if(($old['id']??'')!==$replyTo)continue;$same=((string)$old['from']===(string)$me['id']&&(string)$old['to']===$to)||((string)$old['from']===$to&&(string)$old['to']===(string)$me['id']);if($same)$reply=['id'=>$old['id'],'from'=>$old['from'],'text'=>naa_substr((string)($old['text']??''),0,180),'kind'=>$old['kind']??'text','attachment'=>is_array($old['attachment']??null)?$old['attachment']:null,'meta'=>is_array($old['meta']??null)?$old['meta']:[]];break;}}
    $messages=naa_read('messages');$m=['id'=>naa_id('msg'),'from'=>$me['id'],'to'=>$to,'text'=>$text,'kind'=>$kind,'attachment'=>$attachment,'meta'=>$meta,'reply'=>$reply,'createdAt'=>naa_now(),'deliveredAt'=>'','readAt'=>''];$messages[]=$m;if(count($messages)>25000)$messages=array_slice($messages,-18000);naa_write('messages',$messages);
    $preview=$text!==''?naa_substr($text,0,140):($kind==='image'?'Foto':($kind==='audio'?'Áudio':($kind==='video'?'Vídeo':'Arquivo')));
    $messageImage=($kind==='image'&&is_array($attachment)&&!empty($attachment['url']))?(string)$attachment['url']:'';
    naa_add_event(['type'=>'message','userId'=>$to,'fromName'=>$me['name'],'fromId'=>$me['id'],'senderPhoto'=>$me['photo']??'','messageImage'=>$messageImage,'preview'=>$preview,'messageId'=>$m['id'],'url'=>'./index.html?open=messages&peer='.rawurlencode((string)$me['id']).'&msg='.rawurlencode((string)$m['id'])]);
    naa_respond(['ok'=>true,'message'=>$m]);
}


if ($action==='message_edit') {
    $messageId=(string)($in['messageId']??'');$text=trim((string)($in['text']??''));
    if($messageId===''||$text==='')naa_respond(['ok'=>false,'error'=>'Informe a mensagem e o novo texto.'],422);
    if(naa_len($text)>4000)naa_respond(['ok'=>false,'error'=>'Mensagem muito longa.'],422);
    $messages=naa_read('messages');$found=false;$peer='';
    foreach($messages as &$m){
        if((string)($m['id']??'')!==$messageId)continue;
        if((string)($m['from']??'')!==(string)$me['id'])naa_respond(['ok'=>false,'error'=>'Você só pode editar mensagens enviadas por você.'],403);
        if(($m['kind']??'text')==='call')naa_respond(['ok'=>false,'error'=>'Registros de ligação não podem ser editados.'],403);
        if(!empty($m['deletedForAllAt']))naa_respond(['ok'=>false,'error'=>'Esta mensagem já foi excluída.'],409);
        $created=strtotime((string)($m['createdAt']??''))?:0;
        if($created<=0||time()-$created>600)naa_respond(['ok'=>false,'error'=>'O prazo de 10 minutos para editar esta mensagem terminou.'],409);
        $m['text']=$text;$m['editedAt']=naa_now();$peer=(string)($m['to']??'');$found=true;break;
    }unset($m);
    if(!$found)naa_respond(['ok'=>false,'error'=>'Mensagem não encontrada.'],404);
    naa_write('messages',$messages);
    if($peer!=='')naa_add_event(['type'=>'message_status','userId'=>$peer,'messageId'=>$messageId,'status'=>'edited']);
    naa_respond(['ok'=>true,'messageId'=>$messageId,'editedAt'=>naa_now()]);
}

if ($action==='message_delete') {
    $messageId=(string)($in['messageId']??'');$mode=(string)($in['mode']??'self');
    if(!in_array($mode,['self','all'],true))$mode='self';
    $messages=naa_read('messages');$found=false;$peer='';$now=naa_now();
    foreach($messages as &$m){
        if((string)($m['id']??'')!==$messageId)continue;
        if((string)($m['from']??'')!==(string)$me['id'])naa_respond(['ok'=>false,'error'=>'Você só pode excluir mensagens enviadas por você.'],403);
        if(($m['kind']??'text')==='call')naa_respond(['ok'=>false,'error'=>'Registros de ligação são permanentes no histórico e não podem ser excluídos.'],403);
        $created=strtotime((string)($m['createdAt']??''))?:0;
        if($created<=0||time()-$created>600)naa_respond(['ok'=>false,'error'=>'O prazo de 10 minutos para excluir esta mensagem terminou.'],409);
        $peer=(string)($m['to']??'');$found=true;
        if($mode==='all'){
            $m['text']='';$m['attachment']=null;$m['meta']=[];
            $m['kind']='deleted';$m['deletedForAllAt']=$now;$m['deletedBy']=(string)$me['id'];
        }else{
            $deletedFor=is_array($m['deletedFor']??null)?array_map('strval',$m['deletedFor']):[];
            if(!in_array((string)$me['id'],$deletedFor,true))$deletedFor[]=(string)$me['id'];
            $m['deletedFor']=array_values(array_unique($deletedFor));$m['deletedForMeAt']=$now;
        }
        break;
    }unset($m);
    if(!$found)naa_respond(['ok'=>false,'error'=>'Mensagem não encontrada.'],404);
    naa_write('messages',$messages);
    if($peer!=='')naa_add_event(['type'=>'message_status','userId'=>$peer,'messageId'=>$messageId,'status'=>$mode==='all'?'deleted':'changed']);
    naa_respond(['ok'=>true,'messageId'=>$messageId,'mode'=>$mode]);
}

if ($action==='message_delivered') {
    $messageId=(string)($in['messageId']??'');if($messageId==='')naa_respond(['ok'=>false,'error'=>'Mensagem inválida.'],422);
    $messages=naa_read('messages');$changed=false;$sender='';$now=naa_now();
    foreach($messages as &$m){
        if((string)($m['id']??'')!==$messageId||(string)($m['to']??'')!==(string)$me['id'])continue;
        $sender=(string)($m['from']??'');if(empty($m['deliveredAt'])){$m['deliveredAt']=$now;$changed=true;}break;
    }unset($m);
    if($changed){naa_write('messages',$messages);if($sender!=='')naa_add_event(['type'=>'message_status','userId'=>$sender,'messageId'=>$messageId,'status'=>'delivered']);}
    naa_respond(['ok'=>true,'changed'=>$changed]);
}

if ($action==='message_read') {
    $peer=(string)($in['peer']??'');$messages=naa_read('messages');$changed=false;$now=naa_now();$ids=[];
    foreach($messages as &$m){
        if((string)($m['from']??'')!==$peer||(string)($m['to']??'')!==(string)$me['id'])continue;
        if(empty($m['readAt'])){$m['readAt']=$now;$m['deliveredAt']=$m['deliveredAt']?:$now;$changed=true;$ids[]=(string)($m['id']??'');}
    }unset($m);
    if($changed){naa_write('messages',$messages);naa_add_event(['type'=>'message_status','userId'=>$peer,'status'=>'read','messageIds'=>$ids]);}
    $unreadTotal=0;foreach($messages as $m)if((string)($m['to']??'')===(string)$me['id']&&empty($m['readAt']))$unreadTotal++;
    naa_respond(['ok'=>true,'changed'=>$changed,'unreadTotal'=>$unreadTotal,'messageIds'=>$ids]);
}

/* =========================================================
 * AVISOS / EVENTOS
 * ========================================================= */
if ($action==='notice_send') {
    if(!naa_is_admin($me))naa_respond(['ok'=>false,'error'=>'Somente CEO Programador e Núcleo (NAA) podem enviar avisos institucionais.'],403);
    $title=trim((string)($in['title']??''));$body=trim((string)($in['body']??''));$target=(string)($in['target']??'all');$school=trim((string)($in['school']??''));if($title===''||$body==='')naa_respond(['ok'=>false,'error'=>'Informe título e mensagem.'],422);
    $notice=['id'=>naa_id('ntc'),'title'=>$title,'body'=>$body,'target'=>$target,'school'=>$school,'creatorId'=>$me['id'],'creatorName'=>$me['name'],'createdAt'=>naa_now()];$notices=naa_read('notices');$notices[]=$notice;if(count($notices)>3000)$notices=array_slice($notices,-2000);naa_write('notices',$notices);$event=['type'=>'notice','title'=>$title,'body'=>naa_substr($body,0,220)];if(str_starts_with($target,'role:'))$event['role']=substr($target,5);elseif($target==='school'&&$school!=='')$event['school']=$school;naa_add_event($event);naa_respond(['ok'=>true,'notice'=>$notice]);
}

if ($action==='event_seen') {
    $eventId=trim((string)($in['eventId']??''));
    $seq=max(0,(int)($in['seq']??0));
    if($eventId===''&&$seq<=0)naa_respond(['ok'=>false,'error'=>'Evento inválido.'],422);
    if($eventId==='')$eventId='evt_'.$seq;
    $receipts=naa_read('event_receipts');
    $uid=(string)$me['id'];$now=naa_now();$found=false;
    foreach($receipts as &$r){
        if((string)($r['userId']??'')!==$uid||(string)($r['eventId']??'')!==$eventId)continue;
        $r['seenAt']=$r['seenAt']??$now;
        if($seq>0)$r['seq']=$seq;
        $found=true;break;
    }
    unset($r);
    if(!$found)$receipts[]=['userId'=>$uid,'eventId'=>$eventId,'seq'=>$seq,'seenAt'=>$now];
    if(count($receipts)>20000)$receipts=array_slice($receipts,-15000);
    naa_write('event_receipts',$receipts);
    naa_respond(['ok'=>true,'eventId'=>$eventId,'seenAt'=>$now]);
}

if ($action==='events_since') {
    $after=max(0,(int)($in['after']??0));$events=naa_read('events');$cursor=(int)($events?($events[array_key_last($events)]['seq']??0):0);$out=[];
    $uid=(string)$me['id'];$seen=[];
    foreach(naa_read('event_receipts') as $r){
        if((string)($r['userId']??'')!==$uid||empty($r['seenAt']))continue;
        $rid=(string)($r['eventId']??'');if($rid!=='')$seen[$rid]=true;
        $rseq=(int)($r['seq']??0);if($rseq>0)$seen['seq:'.$rseq]=true;
    }
    foreach($events as $e){
        $eseq=(int)($e['seq']??0);if($eseq<=$after)continue;
        if(!empty($e['adminOnly'])&&!naa_is_admin($me))continue;
        if(!naa_event_target_match($e,$me))continue;
        $eid=(string)($e['id']??($eseq>0?'evt_'.$eseq:''));
        if(($eid!==''&&!empty($seen[$eid]))||($eseq>0&&!empty($seen['seq:'.$eseq])))continue;
        $e['seen_at']=null;$out[]=$e;
    }
    naa_respond(['ok'=>true,'events'=>array_slice($out,-100),'cursor'=>$cursor]);
}

/* =========================================================
 * LS MEET 2026 · ARQUIVOS / OFFLINE-FIRST
 * ========================================================= */
function naa_meet_global_moderator_ids(): array {
    $raw=naa_read('meet_global_moderators');$ids=[];
    foreach($raw as $item){$id=is_array($item)?(string)($item['userId']??''):(string)$item;if($id!=='')$ids[$id]=true;}
    return array_keys($ids);
}
function naa_is_global_meet_moderator(array $me): bool {
    return in_array((string)($me['id']??''),naa_meet_global_moderator_ids(),true);
}

function naa_room_allowed(array $room,array $me): bool {
    $roles=$room['roles']??[];$userIds=$room['userIds']??[];
    if(naa_is_admin($me)||naa_is_global_meet_moderator($me)||($room['creatorId']??'')===($me['id']??''))return true;
    if(in_array((string)($me['id']??''),$room['moderators']??[],true))return true;
    if(!$roles&&!$userIds)return true;
    return in_array((string)($me['role']??''),$roles,true)||in_array((string)($me['id']??''),$userIds,true);
}
function naa_room_can_moderate(array $room,array $me): bool {
    return naa_is_admin($me)||naa_is_global_meet_moderator($me)||($room['creatorId']??'')===($me['id']??'')||in_array((string)($me['id']??''),$room['moderators']??[],true);
}
function naa_room_find(string $roomId): ?array {
    foreach(naa_read('rooms') as $r)if(($r['id']??'')===$roomId)return $r;
    return null;
}
function naa_room_controls(array $room,string $userId): array {
    $global=is_array($room['globalControls']??null)?$room['globalControls']:[];
    $personal=is_array(($room['controls']??[])[$userId]??null)?$room['controls'][$userId]:[];
    return array_merge([
        'micBlocked'=>false,'camBlocked'=>false,'screenBlocked'=>false,'chatBlocked'=>false,'pollBlocked'=>false,'expelled'=>false
    ],$global,$personal);
}
function naa_meet_history_touch(array $room,array $me,array $state,bool $joining=false,bool $leaving=false): void {
    $all=naa_read('meeting_history');$rid=(string)$room['id'];$idx=null;
    foreach($all as $i=>$h)if(($h['roomId']??'')===$rid){$idx=$i;break;}
    if($idx===null){
        $all[]=[
            'roomId'=>$rid,'title'=>$room['title']??'LS Meet','creatorId'=>$room['creatorId']??'','creatorName'=>$room['creatorName']??'',
            'scheduledAt'=>$room['scheduledAt']??'','startedAt'=>$room['startedAt']??naa_now(),'closedAt'=>'',
            'targetParticipation'=>$room['targetParticipation']??'','agenda'=>$room['agenda']??'','certificate'=>!empty($room['certificate']),'participants'=>[]
        ];$idx=array_key_last($all);
    }
    $uid=(string)$me['id'];$p=$all[$idx]['participants'][$uid]??[
        'userId'=>$uid,'name'=>$me['name']??'Participante','photo'=>$me['photo']??'','role'=>$me['role']??'',
        'school'=>$me['school']??'','birthDate'=>$me['birthDate']??'','entries'=>0,'exits'=>0,'totalSeconds'=>0,'speechSeconds'=>0,
        'currentEntry'=>'','lastSeen'=>''
    ];
    $oldSpeech=(int)($p['speechSeconds']??0);$oldSeen=strtotime((string)($p['lastSeen']??''))?:0;
    if($joining&&empty($p['currentEntry'])){$p['entries']=(int)$p['entries']+1;$p['currentEntry']=naa_now();}
    if(isset($state['speakerSeconds']))$p['speechSeconds']=max($oldSpeech,max(0,(int)$state['speakerSeconds']));
    $p['lastSeen']=naa_now();
    if($leaving&&!empty($p['currentEntry'])){
        $p['totalSeconds']=(int)$p['totalSeconds']+max(0,time()-strtotime((string)$p['currentEntry']));
        $p['exits']=(int)$p['exits']+1;$p['currentEntry']='';
    }
    /* V6.10.3: relatório não precisa ser regravado a cada heartbeat. */
    $shouldWrite=$joining||$leaving||$oldSeen<time()-12||((int)$p['speechSeconds']-$oldSpeech)>=8;
    if($shouldWrite){$all[$idx]['participants'][$uid]=$p;naa_write_required('meeting_history',$all);}
}
function naa_meet_history_close(string $roomId,string $closedAt): void {
    $all=naa_read('meeting_history');$closedTs=strtotime($closedAt)?:time();
    foreach($all as &$h){
        if(($h['roomId']??'')!==$roomId)continue;
        $h['closedAt']=$closedAt;
        foreach(($h['participants']??[]) as $uid=>$participant){
            if(empty($participant['currentEntry']))continue;
            $started=strtotime((string)$participant['currentEntry'])?:$closedTs;
            $participant['totalSeconds']=(int)($participant['totalSeconds']??0)+max(0,$closedTs-$started);
            $participant['exits']=(int)($participant['exits']??0)+1;
            $participant['currentEntry']='';
            $h['participants'][$uid]=$participant;
        }
    }
    unset($h);naa_write_required('meeting_history',$all);
}

/* NAA 7.5.0: fecha salas que já começaram e ficaram sem heartbeat ativo.
   Isso cobre fechamento de aba, queda de energia ou perda de rede sem meet_leave. */
function naa_meet_reap_empty_started_rooms(int $staleSeconds=55): void {
    $rooms=naa_read('rooms');$parts=naa_read('meet_participants');$now=time();$changed=false;$closed=[];
    foreach($rooms as &$room){
        if(($room['status']??'open')!=='open'||empty($room['startedAt']))continue;
        $rid=(string)($room['id']??'');if($rid==='')continue;
        $active=false;
        foreach($parts as $p){
            if((string)($p['roomId']??'')!==$rid)continue;
            $seen=strtotime((string)($p['lastSeen']??''))?:0;
            if($seen>$now-$staleSeconds){$active=true;break;}
        }
        if($active)continue;
        $closedAt=naa_now();$room['status']='closed';$room['closedAt']=$closedAt;$closed[$rid]=$closedAt;$changed=true;
    }
    unset($room);
    if(!$changed)return;
    naa_write_required('rooms',$rooms);
    if($closed){
        $parts=array_values(array_filter($parts,fn($p)=>!array_key_exists((string)($p['roomId']??''),$closed)));
        naa_write_required('meet_participants',$parts);
    }
    foreach($closed as $rid=>$closedAt)naa_meet_history_close($rid,$closedAt);
}

if ($action==='rooms_list') {
    naa_meet_reap_empty_started_rooms();
    $rooms=[];foreach(array_reverse(naa_read('rooms')) as $r){if(!naa_room_allowed($r,$me))continue;$rooms[]=$r;if(count($rooms)>=120)break;}
    naa_respond(['ok'=>true,'rooms'=>$rooms]);
}

if ($action==='room_create') {
    if(!naa_can_create_meet($me))naa_respond(['ok'=>false,'error'=>'Seu perfil não pode criar salas LS Meet.'],403);
    $title=trim((string)($in['title']??'Reunião NAA'));
    $roles=array_values(array_unique(array_filter(array_map('strval',$in['roles']??[]))));
    $userIds=array_values(array_unique(array_filter(array_map('strval',$in['userIds']??[]))));
    $requestedMediaMode=(string)($in['mediaMode']??'video');$mediaMode=in_array($requestedMediaMode,['video','voice'],true)?$requestedMediaMode:'video';
    $scheduledAt=trim((string)($in['scheduledAt']??''));
    $targetParticipation=trim((string)($in['targetParticipation']??''));
    $agenda=trim((string)($in['agenda']??$in['prescription']??''));
    $certificate=!empty($in['certificate']);
    $locked=!empty($in['locked']);
    $maxVideo=max(1,(int)($in['maxVideo']??4));
    $rooms=naa_read('rooms');
    $room=[
        'id'=>naa_id('room'),'title'=>$title?:'Reunião NAA','creatorId'=>$me['id'],'creatorName'=>$me['name'],
        'roles'=>$roles,'userIds'=>$userIds,'moderators'=>[],'controls'=>[],'globalControls'=>[],
        'mediaMode'=>$mediaMode,'scheduledAt'=>$scheduledAt,'targetParticipation'=>$targetParticipation,'agenda'=>naa_substr($agenda,0,5000),'certificate'=>$certificate,
        'maxVideo'=>$maxVideo,'locked'=>$locked,'initialLocked'=>$locked,'status'=>'open','createdAt'=>naa_now(),'startedAt'=>'','closedAt'=>''
    ];
    $rooms[]=$room;naa_write_required('rooms',$rooms);
    $eventBase=['type'=>'room','title'=>$room['title'],'roomId'=>$room['id'],'url'=>'./index.html?open=meet&room='.rawurlencode($room['id'])];
    if($roles)naa_add_event($eventBase+['roles'=>$roles]);elseif(!$userIds)naa_add_event($eventBase);
    if($userIds)foreach($userIds as $userId)if($userId!==$me['id'])naa_add_event($eventBase+['userId'=>$userId]);
    naa_respond(['ok'=>true,'room'=>$room]);
}

if ($action==='room_close') {
    $id=(string)($in['id']??'');$rooms=naa_read('rooms');$ok=false;$closedAt=naa_now();
    foreach($rooms as &$r){
        if(($r['id']??'')!==$id)continue;
        if(!naa_room_can_moderate($r,$me))naa_respond(['ok'=>false,'error'=>'Sem permissão para encerrar esta sala.'],403);
        $r['status']='closed';$r['closedAt']=$closedAt;$ok=true;
    }unset($r);
    naa_write_required('rooms',$rooms);if($ok)naa_meet_history_close($id,$closedAt);naa_respond(['ok'=>$ok]);
}

if ($action==='room_delete') {
    $id=(string)($in['id']??'');
    if((string)($me['role']??'')!=='ceo_programador')naa_respond(['ok'=>false,'error'=>'Somente o CEO Programador pode apagar uma reunião encerrada.'],403);
    $rooms=naa_read('rooms');$room=null;
    foreach($rooms as $r)if(($r['id']??'')===$id){$room=$r;break;}
    if(!$room)naa_respond(['ok'=>false,'error'=>'Reunião não encontrada.'],404);
    if(($room['status']??'open')!=='closed')naa_respond(['ok'=>false,'error'=>'Encerre a reunião antes de apagá-la.'],409);
    $rooms=array_values(array_filter($rooms,fn($r)=>(string)($r['id']??'')!==$id));
    naa_write_required('rooms',$rooms);
    $pollIds=[];foreach(naa_read('meet_polls') as $poll)if((string)($poll['roomId']??'')===$id)$pollIds[]=(string)($poll['id']??'');
    foreach(['meeting_history','meet_participants','meet_waiting','meet_chat','meet_signals','meet_polls','meet_invites'] as $dataset){
        $rows=naa_read($dataset);$rows=array_values(array_filter($rows,fn($x)=>(string)($x['roomId']??'')!==$id));naa_write_required($dataset,$rows);
    }
    if($pollIds){$votes=naa_read('meet_poll_votes');$votes=array_values(array_filter($votes,fn($v)=>!in_array((string)($v['pollId']??''),$pollIds,true)));naa_write_required('meet_poll_votes',$votes);}
    naa_respond(['ok'=>true,'deleted'=>true,'id'=>$id]);
}

if ($action==='meet_global_moderators_list') {
    if((string)($me['role']??'')!=='ceo_programador')naa_respond(['ok'=>false,'error'=>'Somente o CEO Programador pode definir moderadores globais do LS Meet.'],403);
    $ids=naa_meet_global_moderator_ids();$users=[];
    foreach(naa_read('users') as $u){
        if(($u['active']??true)===false)continue;
        $pub=naa_public_user($u,true);$pub['isMeetModerator']=in_array((string)($u['id']??''),$ids,true);$users[]=$pub;
    }
    usort($users,fn($a,$b)=>strcasecmp((string)($a['name']??''),(string)($b['name']??'')));
    naa_respond(['ok'=>true,'moderatorIds'=>$ids,'users'=>$users]);
}
if ($action==='meet_global_moderators_update') {
    if((string)($me['role']??'')!=='ceo_programador')naa_respond(['ok'=>false,'error'=>'Somente o CEO Programador pode definir moderadores globais do LS Meet.'],403);
    $requested=array_values(array_unique(array_filter(array_map('strval',$in['moderatorIds']??[]))));
    $valid=[];foreach(naa_read('users') as $u)if(($u['active']??true)!==false&&in_array((string)($u['id']??''),$requested,true))$valid[]=(string)$u['id'];
    naa_write_required('meet_global_moderators',$valid);
    naa_respond(['ok'=>true,'moderatorIds'=>$valid]);
}

if ($action==='room_lock') {
    $id=(string)($in['roomId']??'');$rooms=naa_read('rooms');$room=null;
    foreach($rooms as &$r){if(($r['id']??'')!==$id)continue;if(!naa_room_can_moderate($r,$me))naa_respond(['ok'=>false,'error'=>'Sem permissão para trancar a sala.'],403);$r['locked']=!empty($in['locked']);$room=$r;break;}unset($r);
    if(!$room)naa_respond(['ok'=>false,'error'=>'Sala não encontrada.'],404);naa_write_required('rooms',$rooms);
    naa_respond(['ok'=>true,'locked'=>!empty($room['locked'])]);
}


if ($action==='room_update_capacity') {
    $id=(string)($in['roomId']??'');$maxVideo=max(1,(int)($in['maxVideo']??4));
    $rooms=naa_read('rooms');$room=null;
    foreach($rooms as &$r){
        if(($r['id']??'')!==$id)continue;
        if(!naa_room_can_moderate($r,$me))naa_respond(['ok'=>false,'error'=>'Sem permissão para alterar a capacidade da sala.'],403);
        $r['maxVideo']=$maxVideo;$r['updatedAt']=naa_now();$room=$r;break;
    }unset($r);
    if(!$room)naa_respond(['ok'=>false,'error'=>'Sala não encontrada.'],404);
    naa_write_required('rooms',$rooms);
    naa_respond(['ok'=>true,'room'=>$room,'maxVideo'=>$maxVideo,'maxAllowed'=>null]);
}

if ($action==='meet_invite_create') {
    $roomId=(string)($in['roomId']??'');$room=naa_room_find($roomId);if(!$room||($room['status']??'')!=='open')naa_respond(['ok'=>false,'error'=>'Sala não encontrada.'],404);
    if(!naa_room_can_moderate($room,$me))naa_respond(['ok'=>false,'error'=>'Sem permissão para convidar terceiros.'],403);
    $token=bin2hex(random_bytes(24));$expiresAt=gmdate('c',time()+7*24*3600);
    $invites=naa_read('meet_invites');$invites[]=['id'=>naa_id('invite'),'token'=>$token,'roomId'=>$roomId,'inviterId'=>$me['id'],'inviterName'=>$me['name'],'status'=>'open','createdAt'=>naa_now(),'expiresAt'=>$expiresAt,'usedBy'=>''];naa_write_required('meet_invites',$invites);
    $scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';$host=$_SERVER['HTTP_HOST']??'';$base=rtrim(dirname($_SERVER['SCRIPT_NAME']??'/api/naa-platform.php'),'/');$root=preg_replace('#/api$#','',$base);
    $relative=($root?:'').'/cadastro.html?invite='.rawurlencode($token);$url=$host?$scheme.'://'.$host.$relative:$relative;
    naa_respond(['ok'=>true,'token'=>$token,'url'=>$url,'expiresAt'=>$expiresAt]);
}

if ($action==='meet_wait_list') {
    $roomId=(string)($in['roomId']??'');$room=naa_room_find($roomId);if(!$room)naa_respond(['ok'=>false,'error'=>'Sala não encontrada.'],404);
    if(!naa_room_can_moderate($room,$me))naa_respond(['ok'=>false,'error'=>'Sem permissão para ver a sala de espera.'],403);
    $out=array_values(array_filter(naa_read('meet_waiting'),fn($w)=>($w['roomId']??'')===$roomId&&($w['status']??'waiting')==='waiting'));
    $requests=naa_read('registration_requests');$users=naa_read('users');
    foreach($out as &$w){
        if(($w['type']??'user')==='registration'){
            foreach($requests as $r)if(($r['id']??'')===($w['registrationId']??'')){
                $w['role']=$r['role']??($w['role']??'');$w['school']=$r['school']??($w['school']??'');$w['email']=$r['email']??'';$w['whatsapp']=$r['whatsapp']??'';$w['inviterOnly']=true;break;
            }
        } else {
            foreach($users as $u)if((string)($u['id']??'')===(string)($w['userId']??'')){$w['role']=$u['role']??'';$w['school']=$u['school']??'';break;}
        }
        $w['canDecide']=(($w['type']??'user')==='registration')?((string)($w['requestedBy']??'')===(string)($me['id']??'')):naa_room_can_moderate($room,$me);
    }unset($w);
    naa_respond(['ok'=>true,'waiting'=>$out]);
}

if ($action==='meet_wait_status') {
    $roomId=(string)($in['roomId']??'');$status='none';
    foreach(naa_read('meet_waiting') as $w)if(($w['roomId']??'')===$roomId&&($w['userId']??'')===$me['id']){$status=$w['status']??'waiting';break;}
    naa_respond(['ok'=>true,'status'=>$status]);
}

if ($action==='meet_wait_decide') {
    $roomId=(string)($in['roomId']??'');$waitId=(string)($in['waitId']??'');$decision=(string)($in['decision']??'deny')==='approve'?'approved':'denied';
    $rooms=naa_read('rooms');$roomIndex=null;foreach($rooms as $i=>$r)if(($r['id']??'')===$roomId){$roomIndex=$i;break;}if($roomIndex===null)naa_respond(['ok'=>false,'error'=>'Sala não encontrada.'],404);
    $room=$rooms[$roomIndex];
    $waiting=naa_read('meet_waiting');$wIndex=null;foreach($waiting as $i=>$w)if(($w['id']??'')===$waitId&&($w['roomId']??'')===$roomId){$wIndex=$i;break;}if($wIndex===null)naa_respond(['ok'=>false,'error'=>'Pedido não encontrado.'],404);
    $w=$waiting[$wIndex];
    if(($w['type']??'user')==='registration'){
        if((string)($w['requestedBy']??'')!==(string)($me['id']??''))naa_respond(['ok'=>false,'error'=>'Somente quem gerou este convite pode aprovar o cadastro e a entrada do convidado.'],403);
    } elseif(!naa_room_can_moderate($room,$me)) naa_respond(['ok'=>false,'error'=>'Sem permissão para liberar entradas.'],403);
    if(($w['type']??'user')==='registration'){
        $requests=naa_read('registration_requests');$ri=null;foreach($requests as $i=>$r)if(($r['id']??'')===($w['registrationId']??'')){$ri=$i;break;}
        if($ri===null)naa_respond(['ok'=>false,'error'=>'Cadastro do convidado não encontrado.'],404);
        if($decision==='approved'){
            $r=$requests[$ri];$users=naa_read('users');
            foreach($users as $u)if((string)($u['cpf']??'')===(string)$r['cpf']||naa_norm_email((string)($u['email']??''))===naa_norm_email((string)$r['email']))naa_respond(['ok'=>false,'error'=>'Já existe usuário com este CPF ou e-mail.'],409);
            $userRole=(string)($r['role']??'professor');$userSchool=trim((string)($r['school']??''));
            if(!naa_public_registration_role_allowed($userRole))$userRole='professor';
            $user=['id'=>naa_id('usr'),'name'=>$r['name'],'cpf'=>$r['cpf'],'email'=>$r['email'],'whatsapp'=>$r['whatsapp']??'','birthDate'=>$r['birthDate']??'','role'=>$userRole,'school'=>$userSchool,'photo'=>$r['photo'],'allowUpload'=>false,'allowMeetCreate'=>false,'active'=>true,'passwordHash'=>$r['passwordHash'],'forcePasswordChange'=>false,'createdAt'=>naa_now(),'updatedAt'=>naa_now(),'lastLogin'=>''];
            $users[]=$user;naa_write('users',$users);$requests[$ri]['status']='approved';$requests[$ri]['role']=$userRole;$requests[$ri]['school']=$userSchool;$requests[$ri]['approvedAt']=naa_now();$requests[$ri]['approvedBy']=$me['id'];$requests[$ri]['userId']=$user['id'];$requests[$ri]['updatedAt']=naa_now();naa_write('registration_requests',$requests);
            if(!empty($rooms[$roomIndex]['roles'])||!empty($rooms[$roomIndex]['userIds'])){$rooms[$roomIndex]['userIds']=array_values(array_unique(array_merge($rooms[$roomIndex]['userIds']??[],[$user['id']])));}naa_write_required('rooms',$rooms);
            $invites=naa_read('meet_invites');foreach($invites as &$iv)if(($iv['token']??'')===($r['inviteToken']??'')){$iv['usedBy']=$user['id'];$iv['usedAt']=naa_now();}unset($iv);naa_write_required('meet_invites',$invites);
            $waiting[$wIndex]['userId']=$user['id'];$waiting[$wIndex]['status']='approved';$waiting[$wIndex]['decidedAt']=naa_now();$waiting[$wIndex]['decidedBy']=$me['id'];naa_write_required('meet_waiting',$waiting);
            naa_add_event(['type'=>'access_approved','userId'=>$user['id'],'title'=>'Acesso e reunião liberados','body'=>'Seu acesso ao NAA e à reunião foram aprovados.','url'=>'./index.html?open=meet&room='.rawurlencode($roomId)]);
            naa_respond(['ok'=>true,'status'=>'approved','user'=>naa_public_user($user,true)]);
        } else {
            $requests[$ri]['status']='rejected';$requests[$ri]['rejectionReason']='Convite de reunião não liberado.';$requests[$ri]['updatedAt']=naa_now();naa_write('registration_requests',$requests);
        }
    }
    $waiting[$wIndex]['status']=$decision;$waiting[$wIndex]['decidedAt']=naa_now();$waiting[$wIndex]['decidedBy']=$me['id'];naa_write_required('meet_waiting',$waiting);
    naa_respond(['ok'=>true,'status'=>$decision]);
}

if ($action==='meet_moderate') {
    $roomId=(string)($in['roomId']??'');$target=(string)($in['targetId']??'');$kind=(string)($in['kind']??'');$blocked=!empty($in['blocked']);
    $rooms=naa_read('rooms');$idx=null;foreach($rooms as $i=>$r)if(($r['id']??'')===$roomId){$idx=$i;break;}if($idx===null)naa_respond(['ok'=>false,'error'=>'Sala não encontrada.'],404);
    if(!naa_room_can_moderate($rooms[$idx],$me))naa_respond(['ok'=>false,'error'=>'Sem permissão para moderar.'],403);
    if($kind==='moderator'){
        $mods=$rooms[$idx]['moderators']??[];
        if($blocked)$mods=array_values(array_unique(array_merge($mods,[$target])));else $mods=array_values(array_filter($mods,fn($id)=>$id!==$target));
        $rooms[$idx]['moderators']=$mods;
    } elseif($target==='*') {
        $map=['mic'=>'micBlocked','camera'=>'camBlocked','screen'=>'screenBlocked','chat'=>'chatBlocked','poll'=>'pollBlocked'];
        if(!isset($map[$kind]))naa_respond(['ok'=>false,'error'=>'Comando inválido.'],422);
        $rooms[$idx]['globalControls'][$map[$kind]]=$blocked;
    } else {
        $map=['mic'=>'micBlocked','camera'=>'camBlocked','screen'=>'screenBlocked','chat'=>'chatBlocked','poll'=>'pollBlocked','expel'=>'expelled'];
        if(!isset($map[$kind]))naa_respond(['ok'=>false,'error'=>'Comando inválido.'],422);
        $rooms[$idx]['controls'][$target][$map[$kind]]=$blocked;
    }
    naa_write_required('rooms',$rooms);naa_respond(['ok'=>true,'room'=>$rooms[$idx]]);
}

if ($action==='meet_poll_list') {
    $roomId=(string)($in['roomId']??'');$room=naa_room_find($roomId);if(!$room||!naa_room_allowed($room,$me))naa_respond(['ok'=>false,'error'=>'Sala não encontrada.'],404);
    $votes=naa_read('meet_poll_votes');$out=[];
    foreach(array_reverse(naa_read('meet_polls')) as $poll){
        if(($poll['roomId']??'')!==$roomId)continue;$counts=[];
        foreach($poll['options']??[] as $o)$counts[$o['id']]=0;$mine='';
        foreach($votes as $v)if(($v['pollId']??'')===($poll['id']??'')){if(isset($counts[$v['optionId']??'']))$counts[$v['optionId']]++;if(($v['userId']??'')===$me['id'])$mine=$v['optionId']??'';}
        $poll['counts']=$counts;$poll['myVote']=$mine;$poll['totalVotes']=array_sum($counts);$out[]=$poll;
    }
    naa_respond(['ok'=>true,'polls'=>array_slice($out,0,30)]);
}
if ($action==='meet_poll_create') {
    $roomId=(string)($in['roomId']??'');$room=naa_room_find($roomId);if(!$room||!naa_room_can_moderate($room,$me))naa_respond(['ok'=>false,'error'=>'Sem permissão para criar questionários.'],403);
    $question=trim((string)($in['question']??''));$options=array_values(array_filter(array_map('trim',array_map('strval',$in['options']??[]))));
    if($question===''||count($options)<2)naa_respond(['ok'=>false,'error'=>'Informe a pergunta e pelo menos duas opções.'],422);
    $opts=[];foreach($options as $i=>$text)$opts[]=['id'=>naa_id('opt'),'text'=>naa_substr($text,0,180),'order'=>$i+1];
    $poll=['id'=>naa_id('poll'),'roomId'=>$roomId,'question'=>naa_substr($question,0,300),'options'=>$opts,'status'=>'open','creatorId'=>$me['id'],'creatorName'=>$me['name'],'createdAt'=>naa_now(),'closedAt'=>''];
    $polls=naa_read('meet_polls');$polls[]=$poll;naa_write_required('meet_polls',$polls);naa_respond(['ok'=>true,'poll'=>$poll]);
}
if ($action==='meet_poll_vote') {
    $roomId=(string)($in['roomId']??'');$pollId=(string)($in['pollId']??'');$optionId=(string)($in['optionId']??'');$room=naa_room_find($roomId);if(!$room||!naa_room_allowed($room,$me))naa_respond(['ok'=>false,'error'=>'Sala inválida.'],404);
    $controls=naa_room_controls($room,(string)$me['id']);if(!empty($controls['pollBlocked']))naa_respond(['ok'=>false,'error'=>'Sua participação em questionários foi bloqueada pelo moderador.'],403);
    $poll=null;foreach(naa_read('meet_polls') as $p)if(($p['id']??'')===$pollId&&($p['roomId']??'')===$roomId&&($p['status']??'')==='open'){$poll=$p;break;}if(!$poll)naa_respond(['ok'=>false,'error'=>'Questionário encerrado.'],409);
    if(!in_array($optionId,array_column($poll['options']??[],'id'),true))naa_respond(['ok'=>false,'error'=>'Opção inválida.'],422);
    $votes=naa_read('meet_poll_votes');$found=false;foreach($votes as &$v)if(($v['pollId']??'')===$pollId&&($v['userId']??'')===$me['id']){$v['optionId']=$optionId;$v['updatedAt']=naa_now();$found=true;break;}unset($v);
    if(!$found)$votes[]=['pollId'=>$pollId,'userId'=>$me['id'],'optionId'=>$optionId,'createdAt'=>naa_now()];naa_write_required('meet_poll_votes',$votes);naa_respond(['ok'=>true]);
}
if ($action==='meet_poll_close') {
    $roomId=(string)($in['roomId']??'');$pollId=(string)($in['pollId']??'');$room=naa_room_find($roomId);if(!$room||!naa_room_can_moderate($room,$me))naa_respond(['ok'=>false,'error'=>'Sem permissão.'],403);
    $polls=naa_read('meet_polls');$ok=false;foreach($polls as &$p)if(($p['id']??'')===$pollId&&($p['roomId']??'')===$roomId){$p['status']='closed';$p['closedAt']=naa_now();$ok=true;}unset($p);naa_write_required('meet_polls',$polls);naa_respond(['ok'=>$ok]);
}


if ($action==='meet_transcript_status') {
    $roomId=(string)($in['roomId']??'');$room=naa_room_find($roomId);
    if(!$room||!naa_room_allowed($room,$me))naa_respond(['ok'=>false,'error'=>'Reunião não encontrada.'],404);
    $rows=naa_read('meet_transcript_status');$found=false;
    foreach($rows as &$row){if(($row['roomId']??'')===$roomId&&(string)($row['userId']??'')===(string)$me['id']){$row['supported']=!empty($in['supported']);$row['updatedAt']=naa_now();$found=true;break;}}unset($row);
    if(!$found)$rows[]=['roomId'=>$roomId,'userId'=>$me['id'],'name'=>$me['name'],'supported'=>!empty($in['supported']),'updatedAt'=>naa_now()];
    if(count($rows)>3000)$rows=array_slice($rows,-2400);naa_write_required('meet_transcript_status',$rows);naa_respond(['ok'=>true]);
}
if ($action==='meet_transcript_append') {
    $roomId=(string)($in['roomId']??'');$room=naa_room_find($roomId);
    if(!$room||!naa_room_allowed($room,$me))naa_respond(['ok'=>false,'error'=>'Reunião não encontrada.'],404);
    $text=trim((string)($in['text']??''));if($text===''||naa_len($text)>1500)naa_respond(['ok'=>false,'error'=>'Trecho de transcrição inválido.'],422);
    $spokenAt=trim((string)($in['spokenAt']??''));if($spokenAt===''||strtotime($spokenAt)===false)$spokenAt=naa_now();
    $rows=naa_read('meet_transcripts');$rows[]=['id'=>naa_id('speech'),'roomId'=>$roomId,'userId'=>$me['id'],'name'=>$me['name'],'role'=>$me['role']??'','school'=>$me['school']??'','text'=>naa_substr($text,0,1500),'confidence'=>max(0,min(1,(float)($in['confidence']??0))),'spokenAt'=>$spokenAt,'createdAt'=>naa_now()];
    if(count($rows)>12000)$rows=array_slice($rows,-10000);naa_write_required('meet_transcripts',$rows);naa_respond(['ok'=>true]);
}

if ($action==='meet_report_data') {
    $roomId=(string)($in['roomId']??'');$room=naa_room_find($roomId);if(!$room||!naa_room_allowed($room,$me))naa_respond(['ok'=>false,'error'=>'Reunião não encontrada.'],404);
    $history=null;foreach(naa_read('meeting_history') as $h)if(($h['roomId']??'')===$roomId){$history=$h;break;}
    $transcripts=array_values(array_filter(naa_read('meet_transcripts'),fn($t)=>($t['roomId']??'')===$roomId));$transcriptStatus=array_values(array_filter(naa_read('meet_transcript_status'),fn($t)=>($t['roomId']??'')===$roomId));naa_respond(['ok'=>true,'room'=>$room,'history'=>$history?:['roomId'=>$roomId,'title'=>$room['title']??'LS Meet','participants'=>[]],'transcripts'=>$transcripts,'transcriptStatus'=>$transcriptStatus]);
}

if (in_array($action,['meet_join','meet_heartbeat','meet_leave','signal_send','signal_poll','meet_chat_send','meet_chat_list'],true)) {
    $roomId=(string)($in['roomId']??'');$rooms=naa_read('rooms');$roomIndex=null;
    foreach($rooms as $i=>$r)if(($r['id']??'')===$roomId&&($r['status']??'open')==='open'){$roomIndex=$i;break;}
    if($roomIndex===null)naa_respond(['ok'=>false,'error'=>'Sala não encontrada ou encerrada.'],404);
    $room=$rooms[$roomIndex];if(!naa_room_allowed($room,$me))naa_respond(['ok'=>false,'error'=>'Seu perfil não foi convidado para esta sala.'],403);
    $controls=naa_room_controls($room,(string)$me['id']);if(!empty($controls['expelled']))naa_respond(['ok'=>false,'error'=>'Seu acesso a esta sala foi encerrado pelo moderador.','expelled'=>true],403);
    $canModerate=naa_room_can_moderate($room,$me);
    if($action==='meet_join'&&!$canModerate&&!empty($room['scheduledAt'])){
        $scheduledTs=strtotime((string)$room['scheduledAt']);
        if($scheduledTs!==false&&$scheduledTs>time()) naa_respond(['ok'=>false,'error'=>'Esta reunião está agendada para '.date('d/m/Y H:i',$scheduledTs).'.'],409);
    }

    if($action==='meet_chat_send'){
        if(!empty($controls['chatBlocked']))naa_respond(['ok'=>false,'error'=>'Seu chat foi bloqueado pelo moderador.'],403);
        $text=trim((string)($in['text']??''));$toUserId=trim((string)($in['toUserId']??''));
        if($text===''||naa_len($text)>1200)naa_respond(['ok'=>false,'error'=>'Mensagem vazia ou muito longa.'],422);
        $chat=naa_read('meet_chat');$last=end($chat);$seq=(int)($last['seq']??0)+1;
        $msg=['seq'=>$seq,'roomId'=>$roomId,'userId'=>$me['id'],'toUserId'=>$toUserId,'name'=>$me['name'],'photo'=>$me['photo']??'','text'=>$text,'createdAt'=>naa_now()];
        $chat[]=$msg;if(count($chat)>3000)$chat=array_slice($chat,-2200);naa_write_required('meet_chat',$chat);naa_respond(['ok'=>true,'message'=>$msg]);
    }
    if($action==='meet_chat_list'){
        $after=max(0,(int)($in['after']??0));$chat=naa_read('meet_chat');$cursor=(int)($chat?($chat[array_key_last($chat)]['seq']??0):0);
        $out=array_values(array_filter($chat,fn($m)=>($m['roomId']??'')===$roomId&&(int)($m['seq']??0)>$after&&(empty($m['toUserId'])||($m['userId']??'')===$me['id']||($m['toUserId']??'')===$me['id'])));
        naa_respond(['ok'=>true,'messages'=>array_slice($out,-150),'cursor'=>$cursor]);
    }

    /* V6.10.3: sinalização WebRTC é caminho quente. signal_poll/signal_send
       não devem atualizar presença/histórico. Isso reduz drasticamente I/O e
       evita saturação/HTTP 503-507 em hospedagem compartilhada. */
    if($action==='signal_send'){
        $to=(string)($in['to']??'');$signal=$in['signal']??null;
        if(!is_array($signal)||$to==='')naa_respond(['ok'=>false,'error'=>'Sinal inválido.'],422);
        $signals=naa_read('meet_signals');$cut=time()-300;
        $signals=array_values(array_filter($signals,fn($x)=>strtotime((string)($x['createdAt']??''))>$cut));
        $last=end($signals);$seq=(int)($last['seq']??0)+1;
        $signals[]=['seq'=>$seq,'roomId'=>$roomId,'from'=>(string)$me['id'],'to'=>$to,'signal'=>$signal,'createdAt'=>naa_now()];
        if(count($signals)>2200)$signals=array_slice($signals,-1800);
        naa_write_required('meet_signals',$signals);
        naa_respond(['ok'=>true,'seq'=>$seq]);
    }
    if($action==='signal_poll'){
        $after=max(0,(int)($in['after']??0));$signals=naa_read('meet_signals');
        $cursor=(int)($signals?($signals[array_key_last($signals)]['seq']??0):0);
        $out=array_values(array_filter($signals,fn($x)=>($x['roomId']??'')===$roomId&&(string)($x['to']??'')===(string)$me['id']&&(int)($x['seq']??0)>$after));
        naa_respond(['ok'=>true,'signals'=>array_slice($out,-240),'cursor'=>$cursor]);
    }

    $waiting=naa_read('meet_waiting');
    $approved=false;foreach($waiting as $w)if(($w['roomId']??'')===$roomId&&($w['userId']??'')===$me['id']&&($w['status']??'')==='approved'){$approved=true;break;}
    $recentParts=naa_read('meet_participants');$alreadyInside=false;
    foreach($recentParts as $rp)if(($rp['roomId']??'')===$roomId&&(string)($rp['userId']??'')===(string)$me['id']&&strtotime((string)($rp['lastSeen']??''))>time()-45){$alreadyInside=true;break;}
    if($action==='meet_join'&&!$canModerate&&!empty($room['locked'])&&!$approved&&!$alreadyInside){
        $exists=false;foreach($waiting as $w)if(($w['roomId']??'')===$roomId&&($w['userId']??'')===$me['id']&&($w['status']??'')==='waiting'){$exists=true;break;}
        if(!$exists){$waiting[]=['id'=>naa_id('wait'),'roomId'=>$roomId,'type'=>'user','userId'=>$me['id'],'name'=>$me['name'],'photo'=>$me['photo']??'','role'=>$me['role']??'','school'=>$me['school']??'','status'=>'waiting','createdAt'=>naa_now()];naa_write_required('meet_waiting',$waiting);naa_add_event(['type'=>'meet_waiting','userId'=>$room['creatorId']??'','title'=>'Pessoa aguardando na sala','body'=>$me['name'].' pediu para entrar.','roomId'=>$roomId,'url'=>'./index.html?open=meet&room='.rawurlencode($roomId)]);}
        naa_respond(['ok'=>true,'waiting'=>true,'locked'=>true,'room'=>$room]);
    }

    $parts=$recentParts;$now=time();$parts=array_values(array_filter($parts,fn($p)=>($p['roomId']??'')!==$roomId||strtotime((string)($p['lastSeen']??''))>$now-25));
    $state=is_array($in['state']??null)?$in['state']:[];
    if($action==='meet_leave'){
        naa_meet_history_touch($room,$me,$state,false,true);
        $parts=array_values(array_filter($parts,fn($p)=>!(($p['roomId']??'')===$roomId&&($p['userId']??'')===$me['id'])));naa_write_required('meet_participants',$parts);
        $remaining=count(array_filter($parts,fn($p)=>($p['roomId']??'')===$roomId));
        $closed=false;
        if($remaining===0){
            $closedAt=naa_now();$rooms[$roomIndex]['status']='closed';$rooms[$roomIndex]['closedAt']=$closedAt;naa_write_required('rooms',$rooms);naa_meet_history_close($roomId,$closedAt);$closed=true;
        }
        naa_respond(['ok'=>true,'closed'=>$closed]);
    }

    $exists=false;$joining=$action==='meet_join';
    foreach($parts as &$p){
        if(($p['roomId']??'')===$roomId&&($p['userId']??'')===$me['id']){
            $p['lastSeen']=naa_now();$p['state']=array_merge($p['state']??[],$state);$p['controls']=$controls;$p['canModerate']=$canModerate;$exists=true;
        }
    }unset($p);
    if(!$exists)$parts[]=['roomId'=>$roomId,'userId'=>$me['id'],'name'=>$me['name'],'photo'=>$me['photo']??'','birthDate'=>$me['birthDate']??'','role'=>$me['role']??'','school'=>$me['school']??'','state'=>$state,'controls'=>$controls,'canModerate'=>$canModerate,'lastSeen'=>naa_now()];
    naa_write_required('meet_participants',$parts);
    if(empty($rooms[$roomIndex]['startedAt'])){$rooms[$roomIndex]['startedAt']=naa_now();naa_write_required('rooms',$rooms);$room=$rooms[$roomIndex];}
    naa_meet_history_touch($room,$me,$state,$joining&&!$exists,false);

    $roomParts=array_values(array_filter($parts,fn($p)=>($p['roomId']??'')===$roomId));$videoLimit=max(1,(int)($room['maxVideo']??4));$videoIds=array_slice(array_column($roomParts,'userId'),0,$videoLimit);$mode=in_array($me['id'],$videoIds,true)?'video':'spectator';
    $allSignals=naa_read('meet_signals');$signalCursor=(int)($allSignals?($allSignals[array_key_last($allSignals)]['seq']??0):0);
    $waitingCount=$canModerate?count(array_filter($waiting,fn($w)=>($w['roomId']??'')===$roomId&&($w['status']??'waiting')==='waiting')):0;
    if($action==='meet_join'||$action==='meet_heartbeat')naa_respond(['ok'=>true,'mode'=>$mode,'participants'=>$roomParts,'signalCursor'=>$signalCursor,'room'=>$room,'controls'=>$controls,'canModerate'=>$canModerate,'waitingCount'=>$waitingCount,'locked'=>!empty($room['locked'])]);
}

naa_respond(['ok'=>false,'error'=>'Ação inválida.'],400);
