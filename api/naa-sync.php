<?php
declare(strict_types=1);
require_once __DIR__ . '/naa-lib.php';

$in=naa_input();
$me=naa_auth($in);
$action=(string)($in['action']??'');
$dir=NAA_DATA_DIR.'/datasets';
if(!is_dir($dir))@mkdir($dir,0775,true);

function dataset_file(string $id): string { global $dir; return $dir.'/'.preg_replace('/[^a-zA-Z0-9_.-]/','_',$id).'.json'; }
function dataset_read_file(string $file): ?array {$d=json_decode((string)@file_get_contents($file),true);return is_array($d)?$d:null;}
function dataset_row_key(array $r): string {return implode('|',[(string)($r['school']??''),(string)($r['year']??''),(string)($r['component']??''),(string)($r['turma']??'')]);}
function dataset_student_key(array $r): string {return (string)($r['id']??implode('|',[(string)($r['name']??''),(string)($r['school']??''),(string)($r['year']??''),(string)($r['component']??''),(string)($r['turma']??'')]));}
function dataset_skill_key(array $r): string {return implode('|',[(string)($r['year']??''),(string)($r['component']??''),(string)($r['h']??''),(string)($r['entity']??'')]);}
function dataset_item_key(array $r): string {return implode('|',[(string)($r['year']??''),(string)($r['component']??''),(string)($r['school']??''),(string)($r['turma']??''),(string)($r['item']??''),(string)($r['h']??'')]);}
function dataset_merge_list(array $base,array $incoming,callable $key): array {$m=[];foreach($base as $r){if(is_array($r))$m[$key($r)]=$r;}foreach($incoming as $r){if(!is_array($r))continue;$k=$key($r);$m[$k]=array_merge($m[$k]??[],$r);}return array_values($m);}
function dataset_merge(array $old,array $new): array {
    $out=array_merge($old,$new);
    $out['rows']=dataset_merge_list($old['rows']??[],$new['rows']??[],'dataset_row_key');
    $out['students']=dataset_merge_list($old['students']??[],$new['students']??[],'dataset_student_key');
    $out['municipalSkills']=dataset_merge_list($old['municipalSkills']??[],$new['municipalSkills']??[],'dataset_skill_key');
    $out['items']=dataset_merge_list($old['items']??[],$new['items']??[],'dataset_item_key');
    $out['skillLabels']=array_merge($old['skillLabels']??[],$new['skillLabels']??[]);
    $out['componentLabels']=array_merge($old['componentLabels']??[],$new['componentLabels']??[]);
    $out['sourceNames']=array_values(array_unique(array_merge($old['sourceNames']??[],$new['sourceNames']??[])));
    return $out;
}
function dataset_write_file(string $file,array $d): bool {
    $json=json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
    if($json===false)return false;
    $tmp=$file.'.tmp.'.bin2hex(random_bytes(4));
    if(@file_put_contents($tmp,$json,LOCK_EX)===false)return false;
    @chmod($tmp,0660);
    if(!@rename($tmp,$file)){@unlink($tmp);return false;}
    @chmod($file,0660);return true;
}

/* NAA 7.3: datasets também usam SQL quando o SQL global está habilitado.
   O JSON em api/data/datasets continua como espelho/fallback. */
function dataset_store_key(string $id): string {
    $key='dataset:'.$id;
    return strlen($key)<=118?$key:'dataset#'.hash('sha256',$id);
}
function dataset_sql_read(string $id): ?array {
    $pdo=naa_sql_pdo();if(!$pdo)return null;
    try{$st=$pdo->prepare('SELECT payload FROM naa_store WHERE store_key=? LIMIT 1');$st->execute([dataset_store_key($id)]);$raw=$st->fetchColumn();if(!is_string($raw)||$raw==='')return null;$d=json_decode($raw,true);return is_array($d)?$d:null;}catch(Throwable $e){error_log('[NAA dataset SQL read] '.$e->getMessage());return null;}
}
function dataset_sql_write(string $id,array $d): bool {
    $pdo=naa_sql_pdo();if(!$pdo)return true;
    try{return naa_sql_write_payload($pdo,dataset_store_key($id),$d);}catch(Throwable $e){error_log('[NAA dataset SQL write] '.$e->getMessage());return false;}
}
function dataset_sql_delete(string $id): void {
    $pdo=naa_sql_pdo();if(!$pdo)return;
    try{$st=$pdo->prepare('DELETE FROM naa_store WHERE store_key=?');$st->execute([dataset_store_key($id)]);}catch(Throwable $e){error_log('[NAA dataset SQL delete] '.$e->getMessage());}
}
function dataset_read_id(string $id): ?array {
    $sql=dataset_sql_read($id);if($sql)return $sql;
    $file=dataset_file($id);$d=is_file($file)?dataset_read_file($file):null;
    if($d&&naa_sql_pdo())dataset_sql_write($id,$d);
    return $d;
}
function dataset_write_id(string $id,array $d): bool {
    $cfg=naa_db_config();$sql=naa_sql_pdo();
    $sqlOk=$sql?dataset_sql_write($id,$d):true;
    $jsonOk=dataset_write_file(dataset_file($id),$d);
    if($sql)return $sqlOk && (!empty($cfg['mirror_json'])?$jsonOk:true);
    return $jsonOk;
}
function dataset_delete_id(string $id): void {dataset_sql_delete($id);$file=dataset_file($id);if(is_file($file))@unlink($file);}
function dataset_all(): array {
    global $dir;$map=[];
    // SQL principal
    if($pdo=naa_sql_pdo()){
        try{$st=$pdo->query("SELECT payload FROM naa_store WHERE store_key LIKE 'dataset:%' OR store_key LIKE 'dataset#%'");foreach($st->fetchAll(PDO::FETCH_COLUMN) as $raw){$d=json_decode((string)$raw,true);if(is_array($d)&&!empty($d['id']))$map[(string)$d['id']]=$d;}}catch(Throwable $e){error_log('[NAA dataset SQL pull] '.$e->getMessage());}
    }
    // JSON espelho/legado; o mais recente vence e é migrado para SQL.
    foreach(glob($dir.'/*.json')?:[] as $file){$d=dataset_read_file($file);if(!$d||empty($d['id']))continue;$id=(string)$d['id'];$old=$map[$id]??null;$dt=DateTime::createFromFormat(DateTime::ATOM,(string)($d['updatedAt']??''));$ot=$old?DateTime::createFromFormat(DateTime::ATOM,(string)($old['updatedAt']??'')):false;if(!$old||($dt&&(!$ot||$dt>=$ot)))$map[$id]=$d;if(naa_sql_pdo())dataset_sql_write($id,$map[$id]);}
    return array_values($map);
}
function dataset_cnca_layer(array $d): string {
    if((string)($d['segment']??'')!=='iniciais')return'';
    $layer=(string)($d['cncaLayer']??'');if(in_array($layer,['municipio','escola','turma','habilidades'],true))return $layer;
    if(preg_match('/\|cnca:(municipio|escola|turma|habilidades)$/',(string)($d['id']??''),$m))return $m[1];return'';
}
function dataset_cnca_layer_id(array $d,string $layer): string {
    $ref=(int)($d['refYear']??0);$cycle=strtolower(trim((string)($d['cycle']??'Ciclo I')));return 'iniciais|'.$ref.'|'.$cycle.'|cnca:'.$layer;
}
function dataset_finals_layer(array $d): string {
    if((string)($d['segment']??'')!=='finais')return'';
    $layer=(string)($d['finalsLayer']??'');if(in_array($layer,['municipio','escola','turma','habilidades'],true))return $layer;
    if(preg_match('/\|finais:(municipio|escola|turma|habilidades)$/',(string)($d['id']??''),$m))return $m[1];return'';
}
function dataset_finals_layer_id(array $d,string $layer): string {
    $ref=(int)($d['refYear']??0);$cycle=strtolower(trim((string)($d['cycle']??'Ciclo I')));return 'finais|'.$ref.'|'.$cycle.'|finais:'.$layer;
}
function dataset_is_independent_layer(array $d): bool {
    $seg=(string)($d['segment']??'');
    return ($seg==='iniciais'&&dataset_cnca_layer($d)!=='')||($seg==='finais'&&dataset_finals_layer($d)!=='');
}
function dataset_migrate_legacy_cnca(): void {
    foreach(dataset_all() as $d){
        if((string)($d['segment']??'')!=='iniciais'||dataset_cnca_layer($d)!=='')continue;
        $parts=[
          'municipio'=>['rows'=>[],'municipalSkills'=>[]],
          'escola'=>['rows'=>[],'municipalSkills'=>[]],
          'turma'=>['rows'=>[],'municipalSkills'=>[]],
          'habilidades'=>['rows'=>[],'municipalSkills'=>$d['municipalSkills']??[]]
        ];
        foreach($d['rows']??[] as $r){if(!is_array($r))continue;$school=(string)($r['school']??'');if(!empty($r['turma']))$parts['turma']['rows'][]=$r;elseif((string)($r['sourceLevel']??'')==='municipio'||preg_match('/^MUNIC[IÍ]PIO DE /iu',$school))$parts['municipio']['rows'][]=$r;else $parts['escola']['rows'][]=$r;}
        $created=0;
        foreach($parts as $layer=>$p){
            if(count($p['rows'])+count($p['municipalSkills'])===0)continue;
            $id=dataset_cnca_layer_id($d,$layer);
            // Se o slot independente já existe, não misture novamente dados do conjunto
            // legado. Isso evita ressuscitar linhas corrompidas por parsers anteriores.
            $old=dataset_read_id($id);
            if(is_array($old)&&!empty($old)){ $created++; continue; }
            $next=array_merge($d,['id'=>$id,'cncaLayer'=>$layer,'rows'=>$p['rows'],'students'=>[],'municipalSkills'=>$p['municipalSkills'],'items'=>[],'updatedAt'=>naa_now(),'syncStatus'=>'synced']);
            $next['sourceNames']=array_values(array_unique(array_merge($next['sourceNames']??[],['Migração automática CNCA 7.3.1'])));
            if(dataset_write_id($id,$next))$created++;
        }
        if($created>0)dataset_delete_id((string)$d['id']);
    }
}

dataset_migrate_legacy_cnca();

function dataset_migrate_legacy_finals(): void {
    foreach(dataset_all() as $d){
        if((string)($d['segment']??'')!=='finais'||dataset_finals_layer($d)!=='')continue;
        $parts=[
          'municipio'=>['rows'=>[],'municipalSkills'=>[]],
          'escola'=>['rows'=>[],'municipalSkills'=>[]],
          'turma'=>['rows'=>[],'municipalSkills'=>[]],
          'habilidades'=>['rows'=>[],'municipalSkills'=>$d['municipalSkills']??[]]
        ];
        foreach($d['rows']??[] as $r){
            if(!is_array($r))continue;$school=(string)($r['school']??'');
            if(!empty($r['turma']))$parts['turma']['rows'][]=$r;
            elseif((string)($r['sourceLevel']??'')==='municipio'||preg_match('/^MUNIC[IÍ]PIO DE /iu',$school))$parts['municipio']['rows'][]=$r;
            else $parts['escola']['rows'][]=$r;
        }
        $created=0;
        foreach($parts as $layer=>$part){
            if(count($part['rows'])+count($part['municipalSkills'])===0)continue;
            $id=dataset_finals_layer_id($d,$layer);
            $old=dataset_read_id($id);if(is_array($old)&&!empty($old)){ $created++; continue; }
            $next=array_merge($d,['id'=>$id,'finalsLayer'=>$layer,'rows'=>$part['rows'],'students'=>[],'municipalSkills'=>$part['municipalSkills'],'items'=>[],'updatedAt'=>naa_now(),'syncStatus'=>'synced','replaceSlot'=>true]);
            $next['sourceNames']=array_values(array_unique(array_merge($next['sourceNames']??[],['Migração automática Anos Finais 7.4.2'])));
            if(dataset_write_id($id,$next))$created++;
        }
        if($created>0)dataset_delete_id((string)$d['id']);
    }
}
dataset_migrate_legacy_finals();

if($action==='pull'){
    $out=[];foreach(dataset_all() as $d){if(!$d)continue;$out[]=naa_filter_dataset_for_user($d,$me);}usort($out,fn($a,$b)=>strcmp((string)($b['updatedAt']??''),(string)($a['updatedAt']??'')));naa_respond(['ok'=>true,'datasets'=>$out,'storage'=>naa_storage_status(),'layers'=>'independent-v740']);
}
if($action==='upsert'){
    if(!naa_can_upload($me))naa_respond(['ok'=>false,'error'=>'Seu perfil não possui permissão para upload.'],403);
    $d=$in['dataset']??null;if(!is_array($d))naa_respond(['ok'=>false,'error'=>'Dataset ausente.'],422);
    $id=trim((string)($d['id']??''));if($id==='')naa_respond(['ok'=>false,'error'=>'ID ausente.'],422);
    // Anos Iniciais e Anos Finais usam slots independentes por ciclo/modalidade.
    if((string)($d['segment']??'')==='iniciais' && dataset_cnca_layer($d)==='')naa_respond(['ok'=>false,'error'=>'CNCA 7.4 exige Município, Escola, Turma ou Habilidades em slot próprio.'],409);
    if((string)($d['segment']??'')==='finais' && dataset_finals_layer($d)==='')naa_respond(['ok'=>false,'error'=>'Anos Finais 7.4 exige Município, Escola, Turma ou Habilidades em slot próprio.'],409);
    $existing=dataset_read_id($id)??[];$isNew=!$existing;
    $isIndependent=dataset_is_independent_layer($d);
    // Cada card é um snapshot completo. Reenviar substitui SOMENTE o slot exato
    // (segmento + ano letivo + ciclo + modalidade).
    if($isIndependent){
        if(empty($d['uploadedAt'])&&!empty($existing['uploadedAt']))$d['uploadedAt']=$existing['uploadedAt'];
        $d['sourceNames']=array_values(array_unique($d['sourceNames']??[]));
    }else{
        $d=dataset_merge($existing,$d);
    }
    $d['syncStatus']='synced';$d['updatedAt']=naa_now();
    if(!dataset_write_id($id,$d))naa_respond(['ok'=>false,'error'=>'Não foi possível persistir o conjunto. Verifique SQL e permissão de api/data/datasets.'],500);
    $schoolMap=[];foreach(naa_read('schools') as $school){$school=trim((string)$school);if($school!=='')$schoolMap[$school]=true;}foreach(array_merge($d['rows']??[],$d['students']??[],$d['items']??[]) as $row){$school=trim((string)($row['school']??''));if($school!==''&&!preg_match('/^MUNIC[IÍ]PIO DE /iu',$school))$schoolMap[$school]=true;}if($schoolMap){$schools=array_keys($schoolMap);usort($schools,'strnatcasecmp');naa_write('schools',$schools);}
    if($isNew)naa_add_event(['type'=>'cycle','cycle'=>$d['cycle']??'','refYear'=>$d['refYear']??'','segment'=>$d['segment']??'']);
    $visible=naa_filter_dataset_for_user($d,$me);naa_respond(['ok'=>true,'dataset'=>$visible,'storage'=>naa_storage_status(),'counts'=>['rows'=>count($visible['rows']??[]),'students'=>count($visible['students']??[]),'municipalSkills'=>count($visible['municipalSkills']??[]),'items'=>count($visible['items']??[])]]);
}
if($action==='delete'){
    if(!naa_can_upload($me))naa_respond(['ok'=>false,'error'=>'Seu perfil não possui permissão para excluir conjuntos.'],403);$id=trim((string)($in['id']??''));if($id==='')naa_respond(['ok'=>false,'error'=>'ID ausente.'],422);dataset_delete_id($id);naa_respond(['ok'=>true,'deleted'=>$id]);
}
naa_respond(['ok'=>false,'error'=>'Ação inválida.'],400);
