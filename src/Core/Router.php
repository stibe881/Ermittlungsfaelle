<?php
declare(strict_types=1);
namespace ColdCase\Core;

final class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $re = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = [$method, $re, $handler];
    }

    public function dispatch(Request $r): array
    {
        $pathMatched = false;
        foreach ($this->routes as [$m, $re, $h]) {
            if (preg_match($re, $r->path, $mm)) {
                $pathMatched = true;
                if ($m !== $r->method) continue;
                $params = array_filter($mm, 'is_string', ARRAY_FILTER_USE_KEY);
                return $h($r, $params);
            }
        }
        throw $pathMatched ? new HttpError(405, 'method_not_allowed') : new HttpError(404, 'not_found');
    }
}
