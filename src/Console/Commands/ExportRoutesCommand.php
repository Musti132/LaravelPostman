<?php

declare(strict_types=1);

namespace Musti\LaravelPostman\Console\Commands;

use Carbon\Carbon;
use Closure;
use Illuminate\Config\Repository;
use Illuminate\Console\Command;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Str;
use ReflectionClass;

class ExportRoutesCommand extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'laravel:export';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Export routes and FormRequest fields to postman';

    /**
     * Router instance
     *
     * @var \Illuminate\Routing\Router
     */
    protected Router $router;

    /**
     * Config repository
     *
     * @return void
     */
    protected $config;

    /**
     * Postman structure
     *
     * @var array
     */
    protected array $structure;

    /**
     * Routes to ignore
     *
     * @var array
     */
    protected array $ignoredRoutes = [];

    /**
     * Postman directory
     *
     * @var array
     */
    protected array $directory = [];

    /**
     * Route uri
     *
     * @var string
     */
    protected $routeUri;

    /**
     * Full path
     *
     * @var string
     */
    protected $fullPath;

    public function __construct(Router $router, Repository $config)
    {
        parent::__construct();

        $this->router = $router;
        $this->config = $config['laravel-postman'];
    }


    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle(): void
    {
        $this->mergeIgnoreRoutes();
        $this->createPostmanStructure();

        $routes = $this->router->getRoutes();

        foreach ($routes as $route) {

            if (!$this->isApiRoute($route)) {
                continue;
            }

            $method = $route->methods[0];

            if ($method == 'HEAD') {
                continue;
            }

            if ($route->getAction()['uses'] instanceof Closure) {
                continue;
            }

            // Remove api/, versioning
            $routeUri = preg_replace('/^api\/(v\d+\/)/', '', $route->uri);

            // Check if route is ignored
            if ($this->isIgnoredRoute($routeUri)) {
                continue;
            }

            // Remove api prefix, trailing slash, version and end trailing slash in one regex
            $this->routeUri = preg_replace('/(api\/v[0-9]+\/|api\/|\/\{.*\}|\/$)/', '', $route->uri);

            // Create directory if it doesn't exist
            if (!$this->directoryExists($this->routeUri)) {
                $this->createDirectory($this->routeUri);
            }

            // Create postman item
            $item = $this->createItemFromRoute($route, $route->getAction()['controller']);

            $this->addToDirectory($this->routeUri, $item);
        }

        $this->addDirectoryToPostmanStructrue();

        $this->exportJson();

        $this->info('Postman collection exported successfully');
        $this->info('Path: ' . $this->fullPath);
    }

    /**
     * Check if route is api route
     *
     * @param \Illuminate\Routing\Route $route
     * 
     * @return bool
     */
    private function isApiRoute(Route $route): bool
    {

        if (!isset($route->action['middleware'])) {
            return false;
        }

        if (!in_array('api', (array) $route->action['middleware'])) {
            return false;
        }

        return true;
    }

    /**
     * Merge ignored routes from config
     *
     * @return void
     */
    public function mergeIgnoreRoutes(): void
    {
        if (!empty($this->config['ignored_routes']) && is_array($this->config['ignored_routes'])) {
            $this->ignoredRoutes = array_merge($this->ignoredRoutes, $this->config['ignored_routes']);
        }
    }

    /**
     * Check if route is ignored
     *
     * @param string $route
     * 
     * @return void
     */
    private function isIgnoredRoute($route): bool
    {
        foreach ($this->ignoredRoutes as $ignoredRoute) {
            return (fnmatch($ignoredRoute, $route));
        }
        return false;
    }

    /**
     * Create a postman item structure from route
     *
     * @param \Illuminate\Routing\Route $route
     * @param string $controller
     * 
     * @return void
     */
    private function createItemFromRoute(Route $route, $action): array
    {
        [$controller, $method] = explode('@', $action);

        // Check if route uri has multiple parts
        if (strpos($this->routeUri, '/') !== false) {
            $exploded = explode('/', $this->routeUri);
            // Get last part of route uri
            $routeUriName = end($exploded);
        } else {
            // Get route uri
            $routeUriName = $this->routeUri;
        }

        // Create the item name
        $name = Str::ucfirst($routeUriName) . " " . Str::ucfirst($method);

        $this->info('Creating item: ' . $name);

        $isFormDataOrUrlEncoded = ($route->methods[0] == 'PUT' || $route->methods[0] == 'PATCH') ? 'urlencoded' : 'formdata';

        // Create the item structure
        $item = [
            'name' => $name,
            'request' => [
                'method' => $route->methods[0],
                'header' => [],
                'body' => [
                    'mode' => $isFormDataOrUrlEncoded,
                    $isFormDataOrUrlEncoded => [],
                ],
                'url' => [
                    'raw' => "{{app_url}}" . $route->uri,
                    'host' => [
                        '{{app_url}}',
                    ],
                    'path' => explode('/', $route->uri),
                ],
            ],
        ];

        // Check if controller method has FormRequest
        $formdata = $this->checkIfControllerMethodHasFormRequest($controller, $method);

        // Add FormRequest fields to postman item
        if ($formdata != null) {
            $item['request']['body'][$isFormDataOrUrlEncoded] = $formdata;
        }

        return $item;
    }

    /**
     * Check if controller method has FormRequest
     *
     * @param string $controller
     * @param string $method
     * 
     * @return array|null
     */
    private function checkIfControllerMethodHasFormRequest(string $controller, string $method): ?array
    {
        $formRequest = null;

        $reflectionClass = new ReflectionClass($controller);

        if (!$reflectionClass->hasMethod($method)) {
            return null;
        }

        $reflectionMethod = $reflectionClass->getMethod($method);

        $parameters = $reflectionMethod->getParameters();

        foreach ($parameters as $parameter) {
            // Get type of parameter
            $type = $parameter->getType();

            // Check if parameter is method injected & make sure it's a FormRequest 
            if ($type instanceof \ReflectionNamedType && is_subclass_of($type->getName(), FormRequest::class)) {
                $formRequest = $type->getName();
            }
        }

        if ($formRequest == null) {
            return null;
        }

        $formData = $this->createFormDataFromFormRequest($formRequest);

        return $formData;
    }

    /**
     * Create body fields from FormRequest
     *
     * @param string $formRequest
     * 
     * @return array|null
     */
    private function createFormDataFromFormRequest(string $formRequest): ?array
    {
        $formRequest = new $formRequest;

        if (!method_exists($formRequest, 'rules')) {
            return null;
        }

        $rules = $formRequest->rules();

        $formData = [];

        foreach ($rules as $key => $rule) {
            $formData[] = [
                'key' => $key,
                'value' => '',
                'type' => 'text',
            ];
        }

        return $formData;
    }

    /**
     * Create the postman structure
     *
     * 
     * @return array
     */
    private function createPostmanStructure(): array
    {
        $port = $this->config['port'] != null ? ":" . $this->config['port'] : null;

        $this->structure = [
            'variable' => [
                [
                    'key' => 'app_url',
                    'value' => $this->config['app_url'] . $port,
                ],
            ],
            'info' => [
                'name' => $this->config['collection_name'],
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'item' => [],
        ];

        return $this->structure;
    }

    /**
     * Export the postman collection to json
     * 
     * @return void
     */

    private function exportJson(): void
    {
        $json = json_encode($this->structure, JSON_PRETTY_PRINT);

        $fileName = $this->config['collection_name'] . " " . Carbon::now() . '.json';

        $path = $this->config['path'];

        $this->fullPath = $path . "/" . $fileName;

        $file = fopen($this->fullPath, 'w');

        fwrite($file, $json);
        fclose($file);
    }

    /**
     * Add the directory to the postman structure
     * 
     * @return void
     */
    private function addDirectoryToPostmanStructrue(): void
    {
        $this->structure['item'] = $this->convertToPostmanItem($this->directory);
    }

    /**
     * Check if directory exists
     * 
     * @param string $routeUri
     * 
     * @return bool
     */
    private function directoryExists(string $routeUri): bool
    {
        $parts = explode('/', $routeUri);
        $current = $this->directory;
        foreach ($parts as $part) {
            if (!isset($current[$part])) {
                return false;
            }
            $current = $current[$part]['item'];
        }
        return true;
    }

    /**
     * Convert the directory to postman item
     * 
     * @param array $directory
     * 
     * @return array
     */
    private function convertToPostmanItem(array $directory): array
    {
        $items = [];
        foreach ($directory as $name => $item) {
            if (array_key_exists('item', $item)) {
                $items[] = [
                    'name' => $item['name'],
                    'item' => $this->convertToPostmanItem($item['item'])
                ];
            } else {
                $items[] = $item;
            }
        }
        return $items;
    }

    /**
     * Create the directory
     * 
     * @param string $routeUri
     * 
     * @return array
     */
    private function createDirectory(string $routeUri): array
    {
        $parts = explode('/', $routeUri);
        $current = &$this->directory;
        $uri = '';

        foreach ($parts as $part) {
            if (!isset($current[$part])) {
                $current[$part] = [
                    'name' => Str::singular(Str::ucfirst($part)),
                    'item' => [],
                ];
            }
            $current = &$current[$part]['item'];
        }

        return $current;
    }

    /**
     * Get the directory
     * 
     * @param string $routeUri
     * 
     * @return array
     */
    private function getDirectory(string $routeUri): array
    {
        foreach ($this->directory as $directory) {
            if ($directory['name'] == $routeUri) {
                return $directory['item'];
            }
        }
    }

    /**
     * Add to directory
     * 
     * @param string $routeUri
     * @param array $item
     * 
     * @return void
     */
    private function addToDirectory(string $routeUri, array $item): void
    {
        $parts = explode('/', $routeUri);

        $current = &$this->directory;

        foreach ($parts as $part) {
            $current = &$current[$part]['item'];
        }

        $current[] = $item;
    }
}
