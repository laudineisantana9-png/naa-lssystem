<?php
declare(strict_types=1);
require_once __DIR__ . '/naa-lib.php';
naa_origin_guard();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') naa_respond(['ok'=>false,'error'=>'Método não permitido.'],405);
$token=trim((string)($_POST['token']??''));
$me=naa_auth(['token'=>$token]);
if(empty($_FILES['file'])||!is_array($_FILES['file']))naa_respond(['ok'=>false,'error'=>'Arquivo ausente.'],422);
$f=$_FILES['file'];
$uploadError=(int)($f['error']??UPLOAD_ERR_NO_FILE);
if($uploadError!==UPLOAD_ERR_OK){
    $labels=[UPLOAD_ERR_INI_SIZE=>'O arquivo excede o limite configurado no servidor (upload_max_filesize).',UPLOAD_ERR_FORM_SIZE=>'O arquivo excede o limite permitido pelo formulário.',UPLOAD_ERR_PARTIAL=>'O upload foi recebido apenas parcialmente. Tente novamente.',UPLOAD_ERR_NO_FILE=>'Nenhum arquivo foi recebido.',UPLOAD_ERR_NO_TMP_DIR=>'A pasta temporária do servidor não está disponível.',UPLOAD_ERR_CANT_WRITE=>'O servidor não conseguiu gravar o arquivo no disco.',UPLOAD_ERR_EXTENSION=>'Uma extensão do PHP interrompeu o upload.'];
    naa_respond(['ok'=>false,'error'=>$labels[$uploadError]??('Falha no upload. Código '.$uploadError.'.')],422);
}
$size=(int)($f['size']??0);if($size<=0||$size>32*1024*1024)naa_respond(['ok'=>false,'error'=>'O arquivo deve ter no máximo 32 MB.'],422);
$original=trim((string)($f['name']??'arquivo'));
$ext=strtolower(pathinfo($original,PATHINFO_EXTENSION));
$allowed=['jpg','jpeg','png','webp','gif','mp4','webm','mov','mp3','m4a','aac','wav','ogg','oga','opus','pdf','doc','docx','xls','xlsx','csv','txt','zip','ppt','pptx'];
if(!in_array($ext,$allowed,true))naa_respond(['ok'=>false,'error'=>'Tipo de arquivo não permitido.'],422);
$root=dirname(__DIR__).'/uploads/mensagens';
if(!is_dir($root)&&!@mkdir($root,0755,true))naa_respond(['ok'=>false,'error'=>'Não foi possível preparar a pasta de uploads.'],500);
@chmod($root,0755);
// Corrige anexos antigos que tenham sido criados como 0640 e ficado invisíveis para o servidor web.
foreach((array)glob($root.'/*') as $oldFile){if(is_file($oldFile))@chmod($oldFile,0644);}
$ht=$root.'/.htaccess';if(!is_file($ht))@file_put_contents($ht,"Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|cgi|pl|py|sh)$\">\nRequire all denied\nDeny from all\n</FilesMatch>\n");
$name=naa_id('att').'.'.$ext;$dest=$root.'/'.$name;
if(!move_uploaded_file((string)$f['tmp_name'],$dest))naa_respond(['ok'=>false,'error'=>'Não foi possível salvar o arquivo.'],500);
@chmod($dest,0644);
if(!is_file($dest)||!is_readable($dest))naa_respond(['ok'=>false,'error'=>'O arquivo foi gravado, mas não ficou acessível para leitura. Verifique as permissões da pasta uploads/mensagens.'],500);
$mime='application/octet-stream';
if(function_exists('finfo_open')){$fi=@finfo_open(FILEINFO_MIME_TYPE);if($fi){$det=@finfo_file($fi,$dest);if(is_string($det)&&$det!=='')$mime=$det;@finfo_close($fi);}}
if($mime==='application/octet-stream'&&!empty($f['type']))$mime=(string)$f['type'];
$url='/uploads/mensagens/'.$name;
naa_respond(['ok'=>true,'attachment'=>['url'=>$url,'name'=>$original,'size'=>$size,'mime'=>$mime,'uploadedBy'=>$me['id'],'createdAt'=>naa_now()]]);
