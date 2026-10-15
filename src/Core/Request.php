<?php
declare(strict_types=1);
namespace ColdCase\Core;

final class Request
{
    public ?array $user = null;
    public function __construct(
        public string $method,
        public string $path,
        public array $query = [],
        public array $body = [],
        public array $headers = [],
        public string $ip = '0.0.0.0',
    ) {}

    public static function fromGlobals(): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $raw = file_get_contents('php://input') ?: '';
        $body = [];
        if ($raw !== '') {
            if (strlen($raw) > 262144) throw new HttpError(413, 'payload_too_large');
            $body = json_decode($raw, true);
            if (!is_array($body)) throw new HttpError(400, 'invalid_json');
        }
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
        }
        return new self($_SERVER['REQUEST_METHOD'] ?? 'GET', $path, $_GET, $body, $headers, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function header(string $n): ?string { return $this->headers[strtolower($n)] ?? null; }

    public function str(string $k, int $max = 500): string
    {
        $v = $this->body[$k] ?? '';
        if (!is_string($v)) return '';
        return mb_substr(trim($v), 0, $max);
    }
}
