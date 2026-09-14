<?php
declare(strict_types=1); require __DIR__.'/common.php';
if(($_SERVER['REQUEST_METHOD']??'GET')==='HEAD'){http_response_code(204);exit;}
try{$p=ls_pdo();ls_boot_schema($p);ls_json(['ok'=>true,'service'=>'ls-sync-v2']);}catch(Throwable $e){ls_json(['ok'=>false,'error'=>'Banco indisponível.'],503);}
