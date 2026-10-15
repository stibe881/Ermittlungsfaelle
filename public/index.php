<?php
declare(strict_types=1);

// Front-Controller für /api/*. Pfad der Anwendungsdateien: standardmässig ../ (relativ zum Webroot),
// überschreibbar mit der Umgebungsvariable CC_APP_ROOT (falls src/ ausserhalb des Webroots liegt).
$appRoot = getenv('CC_APP_ROOT') ?: dirname(__DIR__);
require $appRoot . '/src/bootstrap.php';

use ColdCase\Core\{Config, HttpError, Request, Security};

Security::sendHeaders();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    Config::load();
    $req = Request::fromGlobals();
    $out = \ColdCase\Http\Api::handle($req);
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (HttpError $e) {
    http_response_code($e->status);
    echo json_encode(['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()] + $e->extra], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    @error_log('[coldcase] ' . $e);
    echo json_encode(['error' => ['code' => 'server_error', 'message' => 'Interner Fehler.'] + (Config::isDev() ? ['debug' => $e->getMessage()] : [])]);
}
