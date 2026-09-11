<?php
declare(strict_types=1);
require_once __DIR__ . '/naa-lib.php';

function mt_json(array $data,int $status=200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function mt_config(): array {
    $file=__DIR__.'/transcription-config.php';
    $cfg=is_file($file)?require $file:[];
    return is_array($cfg)?$cfg:[];
}
function mt_room(string $roomId): ?array {foreach(naa_read('rooms') as $r)if((string)($r['id']??'')===$roomId)return $r;return null;}
function mt_global_mods(): array { $ids=[]; foreach(naa_read('meet_global_moderators') as $x){$id=is_array($x)?(string)($x['userId']??''):(string)$x;if($id!=='')$ids[$id]=true;} return array_keys($ids); }
function mt_allowed(array $room,array $me): bool {
    if(naa_is_admin($me)||(string)($room['creatorId']??'')===(string)($me['id']??'')||in_array((string)($me['id']??''),mt_global_mods(),true)||in_array((string)($me['id']??''),$room['moderators']??[],true))return true;
    $roles=is_array($room['roles']??null)?$room['roles']:[];$users=is_array($room['userIds']??null)?$room['userIds']:[];
    if(!$roles&&!$users)return true;
    return in_array((string)($me['role']??''),$roles,true)||in_array((string)($me['id']??''),$users,true);
}
function mt_provider(array $cfg): string {
    $p=(string)($cfg['provider']??'auto');
    if($p==='auto'){
        if(trim((string)($cfg['api_key']??''))!==''&&trim((string)($cfg['api_url']??''))!=='')return 'openai_compatible';
        if(trim((string)($cfg['local_command']??''))!=='')return 'local_command';
        return 'unconfigured';
    }
    return $p;
}
function mt_status_upsert(string $roomId,array $me,bool $supported,string $provider,string $detail=''): void {
    $rows=naa_read('meet_transcript_status');$found=false;
    foreach($rows as &$row){if((string)($row['roomId']??'')===$roomId&&(string)($row['userId']??'')===(string)$me['id']){$row['supported']=$supported;$row['provider']=$provider;$row['detail']=$detail;$row['updatedAt']=naa_now();$found=true;break;}}unset($row);
    if(!$found)$rows[]=['roomId'=>$roomId,'userId'=>$me['id'],'name'=>$me['name'],'supported'=>$supported,'provider'=>$provider,'detail'=>$detail,'updatedAt'=>naa_now()];
    if(count($rows)>3000)$rows=array_slice($rows,-2400);naa_write('meet_transcript_status',$rows);
}
function mt_cleanup_tmp(string $dir,int $ttl): void {foreach(glob($dir.'/*')?:[] as $f)if(is_file($f)&&(@filemtime($f)?:0)<time()-$ttl)@unlink($f);}
function mt_transcribe_http(array $cfg,string $file,string $mime): array {
    if(!function_exists('curl_init'))return ['ok'=>false,'error'=>'A extensão cURL do PHP não está disponível.'];
    $url=trim((string)($cfg['api_url']??''));$key=trim((string)($cfg['api_key']??''));$model=trim((string)($cfg['model']??'whisper-1'));$lang=trim((string)($cfg['language']??'pt'));
    if($url===''||$key==='')return ['ok'=>false,'code'=>'provider_unconfigured','error'=>'Configure NAA_TRANSCRIBE_API_KEY/NAA_TRANSCRIBE_API_URL no servidor.'];
    $post=['file'=>new CURLFile($file,$mime,basename($file)),'model'=>$model,'language'=>$lang,'response_format'=>'json'];
    $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>90,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key,'Accept: application/json'],CURLOPT_POSTFIELDS=>$post]);
    $raw=curl_exec($ch);$err=curl_error($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    if($raw===false||$err!=='')return ['ok'=>false,'error'=>'Falha de conexão com o motor de transcrição: '.$err];
    $d=json_decode((string)$raw,true);if($code<200||$code>=300)return ['ok'=>false,'error'=>'Motor de transcrição respondeu HTTP '.$code.'.'];
    $text=trim((string)($d['text']??$d['transcript']??($d['data']['text']??'')));if($text==='')return ['ok'=>false,'error'=>'O motor não retornou texto para este trecho.'];
    return ['ok'=>true,'text'=>$text,'provider'=>'openai_compatible'];
}
function mt_transcribe_local(array $cfg,string $file): array {
    $tpl=trim((string)($cfg['local_command']??''));if($tpl==='')return ['ok'=>false,'code'=>'provider_unconfigured','error'=>'NAA_WHISPER_COMMAND não foi configurado.'];
    $base=$file.'_out';$out=$base.'.txt';$lang=(string)($cfg['language']??'pt');
    $cmd=strtr($tpl,['%INPUT%'=>escapeshellarg($file),'%OUTPUT%'=>escapeshellarg($out),'%OUTPUT_BASE%'=>escapeshellarg($base),'%LANG%'=>escapeshellarg($lang)]);
    $stdout=[];$rc=0;@exec($cmd.' 2>&1',$stdout,$rc);$text=is_file($out)?trim((string)file_get_contents($out)):trim(implode("\n",$stdout));@unlink($out);
    if($rc!==0||$text==='')return ['ok'=>false,'error'=>'O motor local de transcrição falhou.'];
    return ['ok'=>true,'text'=>$text,'provider'=>'local_command'];
}

if(($_SERVER['REQUEST_METHOD']??'')!=='POST')mt_json(['ok'=>false,'error'=>'Método não permitido.'],405);
naa_origin_guard();
$action=trim((string)($_POST['action']??''));$token=trim((string)($_POST['token']??''));$roomId=trim((string)($_POST['roomId']??''));
if($token===''||$roomId==='')mt_json(['ok'=>false,'error'=>'Sessão e reunião são obrigatórias.'],400);
$me=naa_auth(['token'=>$token]);$room=mt_room($roomId);if(!$room||($room['status']??'open')!=='open'||!mt_allowed($room,$me))mt_json(['ok'=>false,'error'=>'Reunião não encontrada ou acesso negado.'],403);
$cfg=mt_config();$provider=mt_provider($cfg);$configured=$provider!=='unconfigured';
if($action==='status'){
    mt_status_upsert($roomId,$me,$configured,$provider,$configured?'Servidor de transcrição pronto.':'Provider de transcrição não configurado.');
    mt_json(['ok'=>true,'configured'=>$configured,'provider'=>$provider,'error'=>$configured?'':'Configure o motor de transcrição no servidor antes de entrar.']);
}
if($action!=='chunk')mt_json(['ok'=>false,'error'=>'Ação inválida.'],422);
if(!$configured){mt_status_upsert($roomId,$me,false,$provider,'Provider não configurado.');mt_json(['ok'=>false,'code'=>'provider_unconfigured','error'=>'O serviço de transcrição do servidor não está configurado.'],503);}
$seq=max(1,(int)($_POST['seq']??0));$startedAt=trim((string)($_POST['startedAt']??''));if($startedAt===''||strtotime($startedAt)===false)$startedAt=naa_now();
if(empty($_FILES['audio'])||!is_uploaded_file($_FILES['audio']['tmp_name']??''))mt_json(['ok'=>false,'error'=>'Trecho de áudio ausente.'],400);
$max=(int)($cfg['max_bytes']??5*1024*1024);$size=(int)($_FILES['audio']['size']??0);if($size<256||$size>$max)mt_json(['ok'=>false,'error'=>'Trecho de áudio fora do limite permitido.'],413);
$mime=trim((string)($_POST['mime']??($_FILES['audio']['type']??'')));$allowed=['audio/webm','audio/webm;codecs=opus','audio/ogg','audio/ogg;codecs=opus','audio/mp4','video/mp4','application/octet-stream'];if(!in_array(strtolower($mime),$allowed,true)&&!str_starts_with(strtolower($mime),'audio/'))mt_json(['ok'=>false,'error'=>'Formato de áudio não permitido.'],415);
$key=$roomId.':'.(string)$me['id'].':'.$seq;$meta=naa_read('meet_transcription_chunks');foreach($meta as $m)if((string)($m['key']??'')===$key&&($m['status']??'')==='done')mt_json(['ok'=>true,'duplicate'=>true,'text'=>$m['text']??'']);
$tmpDir=NAA_DATA_DIR.'/transcribe_tmp';if(!is_dir($tmpDir))@mkdir($tmpDir,0770,true);if(!is_file($tmpDir.'/.htaccess'))@file_put_contents($tmpDir.'/.htaccess',"Require all denied\nDeny from all\nOptions -Indexes\n");mt_cleanup_tmp($tmpDir,(int)($cfg['temporary_ttl_seconds']??3600));
$ext=str_contains($mime,'mp4')?'m4a':(str_contains($mime,'ogg')?'ogg':'webm');$tmp=$tmpDir.'/'.hash('sha256',$key.random_bytes(8)).'.'.$ext;if(!@move_uploaded_file($_FILES['audio']['tmp_name'],$tmp))mt_json(['ok'=>false,'error'=>'Não foi possível preparar o áudio no servidor.'],507);
$result=$provider==='local_command'?mt_transcribe_local($cfg,$tmp):mt_transcribe_http($cfg,$tmp,$mime);if(empty($result['ok'])){mt_status_upsert($roomId,$me,false,$provider,(string)($result['error']??'Falha de transcrição.'));@unlink($tmp);mt_json(['ok'=>false,'code'=>$result['code']??'transcription_failed','error'=>$result['error']??'Falha de transcrição.'],502);}
$text=trim((string)$result['text']);if($text!==''){
    $trs=naa_read('meet_transcripts');$exists=false;foreach($trs as $t)if((string)($t['sourceKey']??'')===$key){$exists=true;break;}
    if(!$exists){$trs[]=['id'=>naa_id('speech'),'sourceKey'=>$key,'roomId'=>$roomId,'userId'=>$me['id'],'name'=>$me['name'],'role'=>$me['role']??'','school'=>$me['school']??'','text'=>naa_substr($text,0,3000),'confidence'=>0,'provider'=>$result['provider']??$provider,'spokenAt'=>$startedAt,'createdAt'=>naa_now()];if(count($trs)>20000)$trs=array_slice($trs,-16000);naa_write_required('meet_transcripts',$trs);}
}
$meta[]=['key'=>$key,'roomId'=>$roomId,'userId'=>$me['id'],'seq'=>$seq,'status'=>'done','provider'=>$result['provider']??$provider,'text'=>naa_substr($text,0,500),'createdAt'=>naa_now()];if(count($meta)>12000)$meta=array_slice($meta,-9000);naa_write_required('meet_transcription_chunks',$meta);mt_status_upsert($roomId,$me,true,$provider,'Transcrição do servidor ativa.');@unlink($tmp);
mt_json(['ok'=>true,'text'=>$text,'provider'=>$result['provider']??$provider]);
