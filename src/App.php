<?php
declare(strict_types=1);
namespace QrStaging;
require_once __DIR__ . '/Core.php';

interface ScanStore
{
    public function insert(string $qr, array $actor, string $requestId): int;
    /** Return at most limit+1 rows, ordered by descending id. */
    public function page(int $limit, ?int $before): array;
}

final class App
{
    private const ROUTES = [
        '/qr/save_scan.php' => ['POST', ['scanner'], false],
        '/qr/get_random_scans.php' => ['GET', ['administrator'], false],
        '/qr/delete_scan.php' => ['POST', ['administrator'], true],
        '/qr/delete_all_scans.php' => ['POST', ['administrator'], true],
        '/qr/create_employee.php' => ['POST', ['administrator'], true],
        '/qr/dashboard.php' => ['GET', ['administrator'], true],
        '/qr/submit_scan.php' => ['POST', ['scanner'], true],
        '/auth/whoami.php' => ['GET', ['scanner', 'administrator'], false],
    ];

    public function __construct(
        private readonly array $config,
        private readonly \Closure $storeFactory,
        private readonly \Closure $clock
    ) {}

    public function handle(Request $request): Response
    {
        $id = bin2hex(random_bytes(16));
        $headers = [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Vary' => 'Origin',
            'X-Request-ID' => $id,
        ];
        try {
            if (!$request->secure) {
                throw new ApiError(426, 'HTTPS_REQUIRED', 'HTTPS is required.');
            }
            $config = Config::validate($this->config);
            $host = strtolower($request->host);
            if (str_ends_with($host, ':443')) { $host = substr($host, 0, -4); }
            if ($host !== $config['allowed_host']) {
                throw new ApiError(403, 'HOST_NOT_ALLOWED', 'This host is not allowed.');
            }
            $route = self::ROUTES[$request->path] ?? null;
            if ($route === null) { throw new ApiError(404, 'NOT_FOUND', 'Endpoint not found.'); }
            [$method, $roles, $disabled] = $route;
            $origin = $request->header('origin');
            if ($origin !== '') {
                if (!in_array($origin, $config['allowed_origins'], true)) {
                    throw new ApiError(403, 'ORIGIN_NOT_ALLOWED', 'This origin is not allowed.');
                }
                $headers['Access-Control-Allow-Origin'] = $origin;
            }
            if ($request->method === 'OPTIONS' && $origin !== '') {
                if ($request->header('access-control-request-method') !== $method) {
                    throw new ApiError(405, 'METHOD_NOT_ALLOWED', 'Method not allowed.', ['Allow' => $method]);
                }
                $requested = strtolower($request->header('access-control-request-headers'));
                $requested = $requested === '' ? [] : array_map('trim', explode(',', $requested));
                if (array_diff($requested, ['authorization', 'content-type'])) {
                    throw new ApiError(403, 'HEADERS_NOT_ALLOWED', 'Requested headers are not allowed.');
                }
                $headers['Access-Control-Allow-Methods'] = $method;
                $headers['Access-Control-Allow-Headers'] = 'Authorization, Content-Type';
                return new Response(204, null, $headers); // Metadata-only preflight, no auth/data/DB.
            }
            if ($request->method !== $method) {
                throw new ApiError(405, 'METHOD_NOT_ALLOWED', 'Method not allowed.', ['Allow' => $method]);
            }
            if (strlen($request->body) > 8192) {
                throw new ApiError(413, 'BODY_TOO_LARGE', 'Request body exceeds 8192 bytes.');
            }
            $actor = Auth::authenticate($request, $config, ($this->clock)());
            if (!in_array($actor['role'], $roles, true)) {
                throw new ApiError(403, 'FORBIDDEN', 'Your role cannot perform this operation.');
            }
            if ($disabled) {
                throw new ApiError(501, 'FEATURE_DISABLED', 'This operation is disabled pending business-rule approval.');
            }
            if ($request->path === '/auth/whoami.php') {
                if ($request->query !== []) { throw new ApiError(400, 'INVALID_QUERY', 'No query parameters are accepted.'); }
                return new Response(200, ['status' => 'success', 'identity' => $actor, 'request_id' => $id], $headers);
            }
            if ($request->path === '/qr/save_scan.php') {
                if ($request->query !== []) { throw new ApiError(400, 'INVALID_QUERY', 'No query parameters are accepted.'); }
                $qr = Input::qr($request);
                /** @var ScanStore $store */
                $store = ($this->storeFactory)();
                $recordId = $store->insert($qr, $actor, $id);
                return new Response(201, [
                    'status' => 'success', 'message' => 'Scan saved successfully',
                    'id' => $recordId, 'request_id' => $id,
                ], $headers);
            }
            [$limit, $before] = Input::pagination($request->query);
            /** @var ScanStore $store */
            $store = ($this->storeFactory)();
            $rows = $store->page($limit, $before);
            $hasMore = count($rows) > $limit;
            $rows = array_slice($rows, 0, $limit);
            $next = $hasMore && $rows !== [] ? (int)$rows[count($rows)-1]['id'] : null;
            return new Response(200, [
                'status' => 'success', 'count' => count($rows), 'data' => $rows,
                'pagination' => ['limit' => $limit, 'has_more' => $hasMore, 'next_before_id' => $next],
                'request_id' => $id,
            ], $headers);
        } catch (ApiError $error) {
            return new Response($error->status, [
                'status' => 'error', 'code' => $error->errorCode,
                'message' => $error->getMessage(), 'request_id' => $id,
            ], array_merge($headers, $error->headers));
        } catch (\Throwable $error) {
            // Never log the exception message, SQL, request body, token, or DB credentials.
            error_log('qr_staging request_id=' . $id . ' event=storage_failure class=' . get_class($error));
            return new Response(503, [
                'status' => 'error', 'code' => 'SERVICE_UNAVAILABLE',
                'message' => 'The service is temporarily unavailable.', 'request_id' => $id,
            ], array_merge($headers, ['Retry-After' => '60']));
        }
    }
}
