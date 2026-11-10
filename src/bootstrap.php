<?php
declare(strict_types=1);
namespace QrStaging;
// This file is included only by fixed public entrypoints.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
require_once __DIR__ . '/Core.php';
require_once __DIR__ . '/App.php';
require_once __DIR__ . '/MysqliStore.php';

function serve(string $route): never
{
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) { return false; }
        // The original message is deliberately not carried into logs/responses.
        throw new \ErrorException('Runtime operation failed.', 0, $severity, $file, $line);
    });
    try {
        $config = Config::load();
        $authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        $redirect = (string)($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($authorization === '') { $authorization = $redirect; }
        elseif ($redirect !== '' && $redirect !== $authorization) { $authorization .= ',' . $redirect; }
        $headers = [
            'authorization' => $authorization,
            'content-type' => (string)($_SERVER['CONTENT_TYPE'] ?? ''),
            'origin' => (string)($_SERVER['HTTP_ORIGIN'] ?? ''),
            'access-control-request-method' => (string)($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] ?? ''),
            'access-control-request-headers' => (string)($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? ''),
        ];
        $stream = fopen('php://input', 'rb');
        $body = $stream === false ? '' : stream_get_contents($stream, 8193);
        if (is_resource($stream)) { fclose($stream); }
        if ($body === false) { throw new \RuntimeException('Body read failed.'); }
        $request = new Request(
            (string)($_SERVER['REQUEST_METHOD'] ?? ''), $route, $headers, $_GET, $body,
            in_array(strtolower((string)($_SERVER['HTTPS'] ?? '')), ['on', '1'], true),
            (string)($_SERVER['HTTP_HOST'] ?? '')
        );
        $app = new App($config, static fn (): ScanStore => new MysqliStore($config['database']),
            static fn (): int => time());
        $response = $app->handle($request);
    } catch (\Throwable) {
        // Misconfiguration fails closed before a DB connection. Do not disclose path/config values.
        error_log('qr_staging event=bootstrap_unavailable');
        $response = new Response(503,
            ['status' => 'error', 'code' => 'SERVICE_UNAVAILABLE', 'message' => 'Staging service is not configured.'],
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store',
             'X-Content-Type-Options' => 'nosniff']);
    }
    try {
        $json = $response->body === null ? '' : json_encode($response->body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    } catch (\Throwable) {
        $response = new Response(500, null, ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store']);
        $json = '{"status":"error","code":"INTERNAL_ERROR","message":"Response could not be encoded."}';
    }
    http_response_code($response->status);
    foreach ($response->headers as $key => $value) { header($key . ': ' . $value); }
    echo $json;
    exit;
}
