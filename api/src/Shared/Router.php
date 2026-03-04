<?php
declare(strict_types=1);

namespace CacheCounty\Shared;

class Router
{
    private array $routes = [];

    public function __construct(private Request $request) {}

    public function get(string $path, array $handler): void    { $this->add('GET',    $path, $handler); }
    public function post(string $path, array $handler): void   { $this->add('POST',   $path, $handler); }
    public function put(string $path, array $handler): void    { $this->add('PUT',    $path, $handler); }
    public function patch(string $path, array $handler): void  { $this->add('PATCH',  $path, $handler); }
    public function delete(string $path, array $handler): void { $this->add('DELETE', $path, $handler); }

    private function add(string $method, string $path, array $handler): void
    {
        $this->routes[] = compact('method', 'path', 'handler');
    }

    public function dispatch(): void
    {
        $method = $this->request->method();
        $uri    = $this->request->uri();

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            // Replace {param} placeholders with regex capture groups
            $pattern = preg_replace('/\{[^}]+\}/', '([^/]+)', $route['path']);
            $pattern = '#^' . $pattern . '$#';

            if (preg_match($pattern, $uri, $matches)) {
                array_shift($matches); // remove full match

                // Map positional matches to named params
                preg_match_all('/\{([^}]+)\}/', $route['path'], $paramNames);
                $params = array_combine($paramNames[1], $matches);
                $this->request->setParams($params);

                [$class, $action] = $route['handler'];
                $controller = new $class();
                $controller->$action($this->request);
                return;
            }
        }

        Response::notFound();
    }
}
