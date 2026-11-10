<?php
declare(strict_types=1);
namespace QrStaging;

final class ApiError extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $safeMessage,
        public readonly array $headers = []
    ) { parent::__construct($safeMessage); }
}

final class Request
{
    public readonly array $headers;
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        array $headers = [],
        public readonly array $query = [],
        public readonly string $body = '',
        public readonly bool $secure = true,
        public readonly string $host = ''
    ) {
        $normalized = [];
        foreach ($headers as $key => $value) {
            $name = strtolower((string)$key);
            $normalized[$name] = isset($normalized[$name])
                ? $normalized[$name] . ',' . (string)$value : (string)$value;
        }
        $this->headers = $normalized;
    }
    public function header(string $name): string { return $this->headers[strtolower($name)] ?? ''; }
}

final class Response
{
    public function __construct(
        public readonly int $status,
        public readonly ?array $body,
        public readonly array $headers
    ) {}
}

final class Config
{
    public static function validate(array $config): array
    {
        $bad = static function (): never {
            throw new ApiError(503, 'CONFIG_NOT_READY', 'Staging configuration is not ready.');
        };
        if (($config['environment'] ?? null) !== 'staging') { $bad(); }
        $host = $config['allowed_host'] ?? null;
        if (!is_string($host) || strlen($host) > 253 ||
            !preg_match('/^qr-staging\.[a-z0-9]+(?:[.-][a-z0-9]+)*$/D', $host)) { $bad(); }
        $origins = $config['allowed_origins'] ?? null;
        if (!is_array($origins) || !array_is_list($origins) || count($origins) > 10) { $bad(); }
        foreach ($origins as $origin) {
            if (!is_string($origin) || !preg_match('#^https://[a-z0-9.-]+(?::[0-9]{1,5})?$#D', $origin)) { $bad(); }
        }
        $db = $config['database'] ?? null;
        if (!is_array($db) || !in_array($db['host'] ?? null, ['127.0.0.1', 'localhost'], true) ||
            ($db['port'] ?? null) !== 3306 || ($db['name'] ?? null) !== 'qr_staging' ||
            !is_string($db['username'] ?? null) || $db['username'] === '' ||
            !is_string($db['password'] ?? null) || $db['password'] === '') { $bad(); }
        $tokens = $config['tokens'] ?? null;
        if (!is_array($tokens) || !array_is_list($tokens) || count($tokens) < 1 || count($tokens) > 100) { $bad(); }
        $seen = [];
        foreach ($tokens as $token) {
            if (!is_array($token) ||
                !is_string($token['sha256'] ?? null) ||
                !preg_match('/^[a-f0-9]{64}$/D', $token['sha256']) ||
                isset($seen[$token['sha256']]) ||
                !is_string($token['subject'] ?? null) ||
                !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $token['subject']) ||
                !in_array($token['role'] ?? null, ['scanner', 'administrator'], true) ||
                !is_int($token['issued_at'] ?? null) || $token['issued_at'] < 1 ||
                !is_int($token['expires_at'] ?? null) ||
                $token['expires_at'] <= $token['issued_at'] ||
                $token['expires_at'] - $token['issued_at'] > 28800 ||
                !is_bool($token['revoked'] ?? null)) { $bad(); }
            $seen[$token['sha256']] = true;
        }
        return $config;
    }

    public static function load(): array
    {
        $path = getenv('QR_STAGING_CONFIG');
        $resolved = is_string($path) && $path !== '' ? realpath($path) : false;
        $public = realpath(__DIR__ . '/../public');
        if ($resolved === false || $public === false ||
            str_starts_with(strtolower(str_replace('\\', '/', $resolved)), strtolower(str_replace('\\', '/', $public)) . '/') ||
            !is_file($resolved) || !is_readable($resolved) || filesize($resolved) > 65536) {
            throw new ApiError(503, 'CONFIG_NOT_READY', 'Staging configuration is not ready.');
        }
        if (PHP_OS_FAMILY !== 'Windows' && ((fileperms($resolved) & 0077) !== 0)) {
            throw new ApiError(503, 'CONFIG_NOT_READY', 'Staging configuration is not ready.');
        }
        try {
            $config = json_decode((string)file_get_contents($resolved), true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new ApiError(503, 'CONFIG_NOT_READY', 'Staging configuration is not ready.');
        }
        if (!is_array($config)) {
            throw new ApiError(503, 'CONFIG_NOT_READY', 'Staging configuration is not ready.');
        }
        return self::validate($config);
    }
}

final class Auth
{
    public static function authenticate(Request $request, array $config, int $now): array
    {
        $header = $request->header('authorization');
        if (!preg_match('/^Bearer ([A-Za-z0-9_-]{43})$/Di', $header, $match)) {
            self::deny();
        }
        $digest = hash('sha256', $match[1]);
        $found = null;
        foreach ($config['tokens'] as $token) {
            if (hash_equals($token['sha256'], $digest)) { $found = $token; }
        }
        if ($found === null || $found['revoked'] ||
            $now < $found['issued_at'] || $now >= $found['expires_at']) { self::deny(); }
        return ['subject' => $found['subject'], 'role' => $found['role'], 'expires_at' => $found['expires_at']];
    }
    private static function deny(): never
    {
        throw new ApiError(401, 'UNAUTHENTICATED', 'A valid bearer token is required.',
            ['WWW-Authenticate' => 'Bearer realm="qr-staging"']);
    }
}

final class Input
{
    public static function qr(Request $request): string
    {
        $type = strtolower(trim(explode(';', $request->header('content-type'))[0]));
        if ($type !== 'application/json') {
            throw new ApiError(415, 'UNSUPPORTED_MEDIA_TYPE', 'Use application/json.');
        }
        try {
            $value = json_decode($request->body, false, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiError(400, 'INVALID_JSON', 'A valid JSON object is required.');
        }
        if (!$value instanceof \stdClass || array_keys(get_object_vars($value)) !== ['qr_data'] ||
            !is_string($value->qr_data) || $value->qr_data === '' ||
            strlen($value->qr_data) > 4096 || str_contains($value->qr_data, "\0") ||
            preg_match('//u', $value->qr_data) !== 1) {
            throw new ApiError(422, 'INVALID_SCAN', 'qr_data must be a nonempty UTF-8 string of at most 4096 bytes without NUL.');
        }
        return $value->qr_data; // Opaque content: never trim, interpret, follow URLs or map employees.
    }

    public static function pagination(array $query): array
    {
        if (array_diff(array_keys($query), ['limit', 'before_id'])) {
            throw new ApiError(400, 'INVALID_QUERY', 'Unsupported query parameter.');
        }
        $limit = isset($query['limit']) ? self::positiveInteger($query['limit'], 100) : 25;
        $before = isset($query['before_id']) ? self::positiveInteger($query['before_id'], 2147483647) : null;
        return [$limit, $before];
    }

    private static function positiveInteger(mixed $value, int $max): int
    {
        if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,9}$/D', $value) ||
            (float)$value > $max) {
            throw new ApiError(400, 'INVALID_QUERY', 'Pagination values must be bounded positive integers.');
        }
        return (int)$value;
    }
}
