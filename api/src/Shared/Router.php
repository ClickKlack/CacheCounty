<?php
declare(strict_types=1);

namespace CacheCounty\Shared;

/**
 * Matches the request against the route table and enforces access before the
 * controller runs.
 *
 * Every route must declare its Access level – there is no default. A route
 * registered without one fails immediately (ArgumentCountError), so a new
 * endpoint can never become public by accident.
 */
class Router
{
    private array $routes = [];

    public function __construct(private Request $request) {}

    public function get(string $path, array $handler, Access $access): void    { $this->add('GET',    $path, $handler, $access); }
    public function post(string $path, array $handler, Access $access): void   { $this->add('POST',   $path, $handler, $access); }
    public function put(string $path, array $handler, Access $access): void    { $this->add('PUT',    $path, $handler, $access); }
    public function patch(string $path, array $handler, Access $access): void  { $this->add('PATCH',  $path, $handler, $access); }
    public function delete(string $path, array $handler, Access $access): void { $this->add('DELETE', $path, $handler, $access); }

    private function add(string $method, string $path, array $handler, Access $access): void
    {
        $this->routes[] = compact('method', 'path', 'handler', 'access');
    }

    /**
     * Registered routes in registration order (used by the route completeness test).
     *
     * @return list<array{method: string, path: string, handler: array, access: Access}>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    public function dispatch(): void
    {
        $method = $this->request->method();
        $uri    = $this->request->uri();

        // HEAD behaves like GET; the web server drops the body
        $matchMethod = $method === 'HEAD' ? 'GET' : $method;

        foreach ($this->routes as $route) {
            if ($route['method'] !== $matchMethod) {
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

                // CSRF protection for state-changing requests, then access control
                OriginCheck::enforce($this->request);
                $this->authorize($route['access']);

                [$class, $action] = $route['handler'];
                $controller = new $class();
                $controller->$action($this->request);
                return;
            }
        }

        Response::notFound();
    }

    /**
     * Exits with 401/403 if the access level is not met. The authenticated user is
     * stored on the request; the Guard calls in the controllers reuse it.
     */
    private function authorize(Access $access): void
    {
        match ($access) {
            Access::Public => null,
            Access::User   => Guard::requireAuth($this->request),
            Access::Admin  => Guard::requireAdmin($this->request),
        };
    }
}
