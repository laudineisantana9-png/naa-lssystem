<?php
/**
 * NAA 6.51.0 - prévia inline do PDF SABE.
 * POST: recebe application/pdf, armazena uma prévia temporária e devolve token.
 * GET: api/sabe-pdf.php?token=... (inline) ou &download=1.
 * As rotas /pdf/sabe/{token} continuam opcionais quando mod_rewrite estiver ativo.
 */
declare(strict_types=1);

$dir = __DIR__ . '/data/pdf-preview';
if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>'Diretório de prévia indisponível no servidor.'], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(500); echo 'Diretório de prévia indisponível.';
    }
    exit;
}

foreach (glob($dir . '/*.pdf') ?: [] as $f) {
    $mtime = @filemtime($f);
    if ($mtime && $mtime < time() - 86400) @unlink($f);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) < 5 || substr($raw, 0, 5) !== '%PDF-') {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Conteúdo PDF inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (strlen($raw) > 30 * 1024 * 1024) {
        http_response_code(413);
        echo json_encode(['ok'=>false,'error'=>'PDF excede o limite da prévia.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try { $token = bin2hex(random_bytes(18)); }
    catch (Throwable $e) { $token = hash('sha256', uniqid('', true) . microtime(true)); $token = substr($token, 0, 36); }
    $path = $dir . '/' . $token . '.pdf';
    if (@file_put_contents($path, $raw, LOCK_EX) === false) {
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>'Não foi possível criar a prévia no servidor.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    @chmod($path, 0640);
    echo json_encode([
        'ok'=>true,
        'token'=>$token,
        'url'=>'/pdf/sabe/'.$token,
        'downloadUrl'=>'/pdf/sabe/'.$token.'/download'
    ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    header('Allow: GET, HEAD, POST'); http_response_code(405); exit;
}

$token = strtolower((string)($_GET['token'] ?? ''));
if (!preg_match('/^[a-f0-9]{36}$/', $token)) { http_response_code(404); exit('Prévia não encontrada.'); }
$path = $dir . '/' . $token . '.pdf';
if (!is_file($path)) { http_response_code(404); exit('Prévia expirada ou não encontrada.'); }

$size = (int)filesize($path);
$download = ($_GET['download'] ?? '') === '1';
$filename = 'Plano_Acao_Pedagogica_SABE.pdf';
header('Content-Type: application/pdf');
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
header('Cross-Origin-Resource-Policy: same-origin');
header('Accept-Ranges: bytes');
header('Content-Disposition: '.($download?'attachment':'inline').'; filename="'.$filename.'"');

$start = 0; $end = max(0, $size - 1); $status = 200;
$range = (string)($_SERVER['HTTP_RANGE'] ?? '');
if (!$download && $range && preg_match('/bytes=(\d*)-(\d*)/i', $range, $m)) {
    if ($m[1] !== '') $start = max(0, (int)$m[1]);
    if ($m[2] !== '') $end = min($end, (int)$m[2]);
    if ($m[1] === '' && $m[2] !== '') { $length = min($size, (int)$m[2]); $start = max(0, $size - $length); $end = max(0, $size - 1); }
    if ($start > $end || $start >= $size) { header('Content-Range: bytes */'.$size); http_response_code(416); exit; }
    $status = 206;
    http_response_code(206);
    header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);
}
$length = $end - $start + 1;
header('Content-Length: '.$length);
if ($_SERVER['REQUEST_METHOD'] === 'HEAD') exit;
$fh = fopen($path, 'rb');
if (!$fh) { http_response_code(500); exit; }
fseek($fh, $start);
$remaining = $length;
while ($remaining > 0 && !feof($fh)) {
    $chunk = fread($fh, min(65536, $remaining));
    if ($chunk === false) break;
    echo $chunk;
    $remaining -= strlen($chunk);
    if (connection_aborted()) break;
}
fclose($fh);
