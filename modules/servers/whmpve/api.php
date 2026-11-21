<?php
declare(strict_types=1);
// Reject malformed/oversized JSON before WHMCS parses the request during init.
// Authentication and all resource operations remain in Http::dispatch().
if (($_SERVER['REQUEST_METHOD'] ?? '')==='POST') {
    $reject=static function(int $code,string $key): never {
        require_once __DIR__.'/lib/Lang.php';
        http_response_code($code);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
        $message=\Whmpve\Lang::text($key,[],$_SERVER['HTTP_X_WHMPVE_LANGUAGE'] ?? '');
        echo json_encode(['ok'=>false,'error'=>$message],JSON_UNESCAPED_UNICODE);exit;
    };
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0)>65536) { $reject(413,'message.request_too_large'); }
    $raw=file_get_contents('php://input',false,null,0,65537);
    if (strlen($raw)>65536) { $reject(413,'message.request_too_large'); }
    if (str_starts_with($_SERVER['CONTENT_TYPE'] ?? '','application/json')) {
        try { $decoded=json_decode($raw,true,32,JSON_THROW_ON_ERROR); }
        catch (JsonException) { $reject(400,'message.invalid_json_request'); }
        if (!is_array($decoded)) { $reject(400,'message.invalid_request_data'); }
        $GLOBALS['whmpve_request_payload']=$decoded;
    }
}
// A JSON endpoint is not an admin-directory page. Bootstrap the shared session;
// Http::admin separately enforces the authenticated admin and addon role ACL.
define('CLIENTAREA',true);
require dirname(__DIR__,3).'/init.php';
require __DIR__.'/lib/bootstrap.php';
\Whmpve\Http::dispatch();
