<?php
/**
 * Peakopay — Minimal regex router.
 *
 * Pattern syntax: literal path with {param} placeholders, e.g. /pay/{token}.
 */
declare(strict_types=1);

final class Router
{
    /** @var array<int, array{method:string, regex:string, params:string[], handler:mixed}> */
    private array $routes = [];

    public function add(string $method, string $pattern, mixed $handler): void
    {
        $params = [];
        $regex  = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
            static function (array $m) use (&$params): string {
                $params[] = $m[1];
                return '([^/]+)';
            },
            $pattern
        );
        $this->routes[] = [
            'method' => strtoupper($method),
            'regex'  => '#^' . $regex . '$#',
            'params' => $params,
            'handler'=> $handler,
        ];
    }

    public function get(string $pattern, mixed $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, mixed $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function any(string $pattern, mixed $handler): void
    {
        $this->add('GET', $pattern, $handler);
        $this->add('POST', $pattern, $handler);
    }

    /** Match current (or given) request and call the handler with named params. */
    public function dispatch(?string $method = null, ?string $path = null): void
    {
        $method = strtoupper($method ?? Http::method());
        $path   = $path ?? Http::path();
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            if ($route['method'] !== $method) {
                $pathMatched = true; // exists with another verb
                continue;
            }
            array_shift($m);
            $args = [];
            foreach ($route['params'] as $i => $name) {
                $args[$name] = rawurldecode($m[$i]);
            }
            $handler = $route['handler'];
            if (is_string($handler) && str_contains($handler, '::')) {
                $handler = explode('::', $handler, 2);
            }
            if (!is_callable($handler)) {
                Http::apiError('server_error', 'Invalid route handler.', 500);
            }
            $result = call_user_func($handler, $args);
            if (is_string($result) && $result !== '') {
                echo $result;
            }
            return;
        }

        if ($pathMatched) {
            Http::apiError('method_not_allowed', 'متد درخواست مجاز نیست.', 405);
        }
        Http::notFound();
    }
}
