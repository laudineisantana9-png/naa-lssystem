<?php
declare(strict_types=1);
require_once __DIR__ . '/naa-lib.php';

$in = naa_input();
$me = naa_auth($in);
$roomId = trim((string)($in['roomId'] ?? ''));
if ($roomId==='') naa_respond(['ok'=>false,'error'=>'Reunião inválida.'],422);
$room = null;
foreach (naa_read('rooms') as $r) if (($r['id']??'')===$roomId) {$room=$r;break;}
if (!$room) naa_respond(['ok'=>false,'error'=>'Reunião não encontrada.'],404);

$globalModeratorIds = [];
foreach (naa_read('meet_global_moderators') as $item) {
    $gid = is_array($item) ? (string)($item['userId'] ?? '') : (string)$item;
    if ($gid !== '') $globalModeratorIds[$gid] = true;
}
$allowed = naa_is_admin($me)
    || isset($globalModeratorIds[(string)($me['id']??'')])
    || ($room['creatorId']??'')===($me['id']??'')
    || in_array((string)($me['id']??''), $room['moderators']??[], true)
    || in_array((string)($me['id']??''), $room['userIds']??[], true)
    || in_array((string)($me['role']??''), $room['roles']??[], true);
if (!$allowed) naa_respond(['ok'=>false,'error'=>'Sem permissão para este relatório.'],403);

$history = null;
foreach (naa_read('meeting_history') as $h) if (($h['roomId']??'')===$roomId) {$history=$h;break;}
if (!$history) $history=['participants'=>[],'startedAt'=>$room['startedAt']??'','closedAt'=>$room['closedAt']??''];

$transcripts=array_values(array_filter(naa_read('meet_transcripts'),fn($t)=>($t['roomId']??'')===$roomId));
usort($transcripts,fn($a,$b)=>strcmp((string)($a['spokenAt']??$a['createdAt']??''),(string)($b['spokenAt']??$b['createdAt']??'')));
$transcriptStatus=[];
foreach(naa_read('meet_transcript_status') as $s) if(($s['roomId']??'')===$roomId) $transcriptStatus[(string)($s['userId']??'')] = !empty($s['supported']);

function pdf_text(string $s): string {
    $s = str_replace(["\r","\n"], ' ', $s);
    $enc = @iconv('UTF-8','Windows-1252//TRANSLIT',$s);
    if ($enc===false) $enc = preg_replace('/[^\x20-\x7E]/','?', $s) ?? $s;
    return str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], $enc);
}
function fmt_seconds(int $sec): string {
    $sec=max(0,$sec);$h=intdiv($sec,3600);$m=intdiv($sec%3600,60);$s=$sec%60;
    return sprintf('%02d:%02d:%02d',$h,$m,$s);
}
function fmt_date(string $iso): string {
    if($iso==='')return '-';$ts=strtotime($iso);return $ts?date('d/m/Y H:i',$ts):$iso;
}
function fmt_clock(string $iso): string {
    if($iso==='')return '--:--:--';$ts=strtotime($iso);return $ts?date('H:i:s',$ts):'--:--:--';
}
function wrap_report(string $text,int $limit=88,string $prefix=''): array {
    $text=trim(preg_replace('/\s+/u',' ',$text)??$text);
    if($text==='')return [$prefix.'Não informado'];
    $words=preg_split('/\s+/u',$text)?:[];$out=[];$line=$prefix;
    foreach($words as $word){
        $candidate=trim($line.' '.$word);
        if(strlen($candidate)>$limit && trim($line)!==trim($prefix)){$out[]=$line;$line=str_repeat(' ',strlen($prefix)).$word;}
        else $line=$candidate;
    }
    if(trim($line)!=='')$out[]=$line;
    return $out;
}
function image_jpeg_info(string $path): array {
    if(!is_file($path))return ['ok'=>false];$data=(string)@file_get_contents($path);$info=@getimagesize($path);
    if($data===''||!is_array($info)||($info[2]??0)!==IMAGETYPE_JPEG)return ['ok'=>false];
    return ['ok'=>true,'data'=>$data,'w'=>(int)$info[0],'h'=>(int)$info[1]];
}

$participants = array_values($history['participants']??[]);
usort($participants, fn($a,$b)=>strnatcasecmp((string)($a['name']??''),(string)($b['name']??'')));
$totalConnected=0;$totalSpeech=0;$totalEntries=0;$totalExits=0;
foreach($participants as $p){$totalConnected+=(int)($p['totalSeconds']??0);$totalSpeech+=(int)($p['speechSeconds']??0);$totalEntries+=(int)($p['entries']??0);$totalExits+=(int)($p['exits']??0);}

$lines=[];
$lines[]=['t'=>'RELATÓRIO INSTITUCIONAL - LS MEET 2026','kind'=>'title'];
$lines[]=['t'=>'Núcleo de Avaliação da Aprendizagem - NAA','kind'=>'subtitle'];
$lines[]=['t'=>'Reunião: '.($room['title']??'LS Meet'),'kind'=>'normal'];
$lines[]=['t'=>'Responsável: '.($room['creatorName']??'NAA'),'kind'=>'normal'];
$lines[]=['t'=>'Agendada: '.fmt_date((string)($room['scheduledAt']??'')),'kind'=>'normal'];
$lines[]=['t'=>'Início: '.fmt_date((string)($history['startedAt']??$room['startedAt']??'')),'kind'=>'normal'];
$lines[]=['t'=>'Encerramento: '.fmt_date((string)($history['closedAt']??$room['closedAt']??'')),'kind'=>'normal'];
$lines[]=['t'=>'Público-alvo: '.((string)($room['targetParticipation']??'') ?: 'Não informado'),'kind'=>'normal'];
$lines[]=['t'=>'Capacidade de mídia configurada: '.max(1,(int)($room['maxVideo']??4)).' aparelho(s)','kind'=>'normal'];
$lines[]=['t'=>'Certificado: '.(!empty($room['certificate'])?'Sim':'Não'),'kind'=>'normal'];
$lines[]=['t'=>'','kind'=>'space'];
$lines[]=['t'=>'PAUTA / OBJETIVOS','kind'=>'heading'];
foreach(wrap_report((string)($room['agenda']??$history['agenda']??''),88,'') as $l)$lines[]=['t'=>$l,'kind'=>'normal'];
$lines[]=['t'=>'','kind'=>'space'];
$lines[]=['t'=>'RESUMO DE PRESENÇA E INTERAÇÃO','kind'=>'heading'];
$lines[]=['t'=>'Participantes únicos: '.count($participants).' | Entradas: '.$totalEntries.' | Saídas: '.$totalExits,'kind'=>'normal'];
$lines[]=['t'=>'Tempo conectado somado: '.fmt_seconds($totalConnected).' | Tempo de interação/fala: '.fmt_seconds($totalSpeech),'kind'=>'normal'];
$lines[]=['t'=>'','kind'=>'space'];
$lines[]=['t'=>'LISTA DE PRESENÇA','kind'=>'heading'];
if(!$participants)$lines[]=['t'=>'Nenhuma participação registrada.','kind'=>'normal'];
foreach($participants as $i=>$p){
    $role=naa_role_label((string)($p['role']??''));$school=trim((string)($p['school']??''));$uid=(string)($p['userId']??'');
    $lines[]=['t'=>sprintf('%02d. %s',$i+1,(string)($p['name']??'Participante')),'kind'=>'person'];
    foreach(wrap_report('Cargo: '.$role.($school!==''?' | Escola: '.$school:''),88,'   ') as $l)$lines[]=['t'=>$l,'kind'=>'normal'];
    $lines[]=['t'=>'   Entradas: '.(int)($p['entries']??0).' | Saídas: '.(int)($p['exits']??0).' | Presença: '.fmt_seconds((int)($p['totalSeconds']??0)).' | Interação/fala: '.fmt_seconds((int)($p['speechSeconds']??0)),'kind'=>'normal'];
    if(array_key_exists($uid,$transcriptStatus) && !$transcriptStatus[$uid]) $lines[]=['t'=>'   Transcrição do servidor: indisponível/não autorizada para este participante.','kind'=>'muted'];
    $lines[]=['t'=>'','kind'=>'space'];
}
$lines[]=['t'=>'TRANSCRIÇÃO CRONOLÓGICA DA REUNIÃO','kind'=>'heading'];
foreach(wrap_report('A transcrição abaixo é produzida pelo processamento do áudio local de cada participante no servidor, mediante consentimento. Ela pode conter imprecisões e não substitui uma ata revisada.',84,'') as $l) $lines[]=['t'=>$l,'kind'=>'muted'];
$lines[]=['t'=>'','kind'=>'space'];
if(!$transcripts){
    $lines[]=['t'=>'Nenhum trecho de fala foi transcrito automaticamente nesta reunião.','kind'=>'normal'];
} else {
    foreach($transcripts as $tr){
        $who=trim((string)($tr['name']??''))?:'Participante';$clock=fmt_clock((string)($tr['spokenAt']??$tr['createdAt']??''));
        $lines[]=['t'=>$clock.' - '.$who,'kind'=>'speaker'];
        foreach(wrap_report((string)($tr['text']??''),84,'   ') as $l)$lines[]=['t'=>$l,'kind'=>'normal'];
        $lines[]=['t'=>'','kind'=>'space'];
    }
}
$lines[]=['t'=>'Documento gerado pelo NAA - Núcleo de Avaliação da Aprendizagem','kind'=>'footer'];
$lines[]=['t'=>'LS Meet 2026 - Desenvolvido por LS System','kind'=>'footer'];

$pref=image_jpeg_info(dirname(__DIR__).'/logo_prefeitura_report.jpg');
$nuc=image_jpeg_info(dirname(__DIR__).'/logo_nucleo_header.jpg');
$water=image_jpeg_info(dirname(__DIR__).'/logo_nucleo_report.jpg');

$objects=[];$objects[1]='<< /Type /Catalog /Pages 2 0 R >>';$objects[3]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
$next=4;$prefObj=0;$nucObj=0;$waterObj=0;$gsObj=0;
foreach([['img'=>$pref,'name'=>'pref'],['img'=>$nuc,'name'=>'nuc'],['img'=>$water,'name'=>'water']] as $entry){
    if(empty($entry['img']['ok']))continue;$obj=$next++;$body='<< /Type /XObject /Subtype /Image /Width '.$entry['img']['w'].' /Height '.$entry['img']['h'].' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($entry['img']['data'])." >>\nstream\n".$entry['img']['data']."\nendstream";$objects[$obj]=$body;
    if($entry['name']==='pref')$prefObj=$obj;elseif($entry['name']==='nuc')$nucObj=$obj;else $waterObj=$obj;
}
if($waterObj){$gsObj=$next++;$objects[$gsObj]='<< /Type /ExtGState /ca 0.038 /CA 0.038 >>';}

// Paginate by approximate line height. Header occupies first ~110 points.
$pages=[];$page=[];$used=0;$maxHeight=655;
foreach($lines as $line){
    $kind=$line['kind'];$h=($kind==='title'?24:($kind==='heading'?21:($kind==='space'?8:($kind==='speaker'?18:15))));
    if($used+$h>$maxHeight && $page){$pages[]=$page;$page=[];$used=0;}
    $page[]=$line;$used+=$h;
}
if($page)$pages[]=$page;if(!$pages)$pages=[[]];

$pageRefs=[];
foreach($pages as $pageIndex=>$chunk){
    $pageObj=$next++;$contentObj=$next++;$pageRefs[]=$pageObj.' 0 R';
    $content="q\n";
    // watermark centered on every page
    if($waterObj){$content.="/GS1 gs\n345 0 0 230 125 286 cm /ImW Do\n";}
    $content.="Q\n";
    // institutional header logos every page
    if($prefObj)$content.="q\n178 0 0 26 44 800 cm /ImP Do\nQ\n";
    if($nucObj)$content.="q\n72 0 0 48 479 786 cm /ImN Do\nQ\n";
    $content.="0.82 0.86 0.90 RG 0.6 w 44 779 m 551 779 l S\n";
    $content.="BT\n/F1 9 Tf\n";
    $y=758;
    foreach($chunk as $line){
        $kind=$line['kind'];$txt=(string)$line['t'];
        $size=9.2;$step=15;
        if($kind==='title'){$size=14;$step=24;}
        elseif($kind==='subtitle'){$size=10.5;$step=19;}
        elseif($kind==='heading'){$size=11.2;$step=21;}
        elseif($kind==='person'||$kind==='speaker'){$size=10.2;$step=18;}
        elseif($kind==='footer'||$kind==='muted'){$size=8.3;$step=14;}
        elseif($kind==='space'){$size=8;$step=8;}
        $content.="/F1 {$size} Tf\n1 0 0 1 45 {$y} Tm (".pdf_text($txt).") Tj\n";$y-=$step;
    }
    $content.="ET\n";

    // Rodapé institucional em todas as páginas.
    // Mantido fora do fluxo principal para não sobrepor o conteúdo do relatório.
    $footerLeft = 'LS Meet - Sistema desenvolvido por LS System - CEO: Laudinei Santana';
    $footerRight = 'Página '.($pageIndex + 1).' de '.count($pages);
    $content.="0.82 0.86 0.90 RG 0.55 w 44 43 m 551 43 l S\n";
    $content.="0.30 0.34 0.40 rg\nBT\n/F1 7.5 Tf\n1 0 0 1 44 27 Tm (".pdf_text($footerLeft).") Tj\n";
    // Alinhamento à direita calculado de forma segura para Helvetica 7.5pt.
    $footerRightWidth = max(0, strlen($footerRight) * 3.65);
    $footerRightX = max(400, 551 - $footerRightWidth);
    $content.="1 0 0 1 ".$footerRightX." 27 Tm (".pdf_text($footerRight).") Tj\nET\n";

    $xparts=['/Font << /F1 3 0 R >>'];
    $ximgs=[];if($prefObj)$ximgs[]='/ImP '.$prefObj.' 0 R';if($nucObj)$ximgs[]='/ImN '.$nucObj.' 0 R';if($waterObj)$ximgs[]='/ImW '.$waterObj.' 0 R';
    if($ximgs)$xparts[]='/XObject << '.implode(' ',$ximgs).' >>';if($gsObj)$xparts[]='/ExtGState << /GS1 '.$gsObj.' 0 R >>';
    $res='<< '.implode(' ',$xparts).' >>';
    $objects[$pageObj]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources '.$res.' /Contents '.$contentObj.' 0 R >>';
    $objects[$contentObj]='<< /Length '.strlen($content)." >>\nstream\n".$content."\nendstream";
}
$objects[2]='<< /Type /Pages /Count '.count($pageRefs).' /Kids ['.implode(' ',$pageRefs).'] >>';ksort($objects);
$pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";$offsets=[0];
foreach($objects as $num=>$body){$offsets[$num]=strlen($pdf);$pdf.=$num." 0 obj\n".$body."\nendobj\n";}
$xref=strlen($pdf);$max=max(array_keys($objects));$pdf.="xref\n0 ".($max+1)."\n0000000000 65535 f \n";
for($i=1;$i<=$max;$i++)$pdf.=sprintf("%010d 00000 n \n",$offsets[$i]??0);
$pdf.="trailer\n<< /Size ".($max+1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="LS_Meet_'.preg_replace('/[^A-Za-z0-9_-]/','_',$roomId).'.pdf"');
header('Content-Length: '.strlen($pdf));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
echo $pdf;
