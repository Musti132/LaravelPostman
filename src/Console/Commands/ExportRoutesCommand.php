<?php

declare(strict_types=1);

namespace Musti\LaravelPostman\Console\Commands;

use Carbon\Carbon;
use Closure;
use Illuminate\Config\Repository;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Psy\VarDumper\Dumper;
use Str;
use ReflectionClass;
use Spatie\LaravelIgnition\Recorders\DumpRecorder\Dump;
use Termwind\Components\Dd;

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

    public function handle()
    {
        if (!empty($this->config['ignored_routes']) && is_array($this->config['ignored_routes'])) {
            $this->ignoredRoutes = array_merge($this->ignoredRoutes, $this->config['ignored_routes']);
        }

        $this->createPostmanStructure();

        $routes = $this->router->getRoutes();

        foreach ($routes as $route) {
            if (!in_array('api', $route->action['middleware'])) {
                continue;
            }

            $method = $route->methods[0];

            if ($method == 'HEAD') {
                continue;
            }
            
            if($route->getAction()['uses'] instanceof Closure) {
                continue;
            }

            // Remove api/, versioning
            $routeUri = preg_replace('/^api\/(v\d+\/)/', '', $route->uri);

            // Check if route is ignored
            if($this->isIgnoredRoute($routeUri)) {
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

    function isIgnoredRoute($route) {
        foreach ($this->ignoredRoutes as $ignoredRoute) {
            return (fnmatch($ignoredRoute, $route));
        }
        return false;
    }

    public function createItemFromRoute($route, $controller = null) : array
    {
        [$controller, $method] = explode('@', $controller);

        // Check if route uri has multiple parts
        if(strpos($this->routeUri, '/') !== false){
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

        // Create the item structure
        $item = [
            'name' => $name,
            'request' => [
                'method' => $route->methods[0],
                'header' => [],
                'body' => [
                    'mode' => 'formdata',
                    'formdata' => [],
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
            $item['request']['body']['formdata'] = $formdata;
        }

        return $item;
    }

    public function checkIfControllerMethodHasFormRequest(string $controller, string $method)
    {
        $formRequest = null;

        $controller = new $controller;

        $reflectionClass = new ReflectionClass($controller);

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

    public function createFormDataFromFormRequest($formRequest)
    {
        $formRequest = new $formRequest;

        if(!method_exists($formRequest, 'rules')) {
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

    public function createPostmanStructure()
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

    public function mergeConfigIngoreList(): void
    {
        $this->ignoredRoutes = array_merge($this->ignoredRoutes, $this->config['ignore_list']);
    }

    public function exportJson(): void
    {
        $json = json_encode($this->structure, JSON_PRETTY_PRINT);

        $fileName = $this->config['collection_name'] . " " . Carbon::now() .'.json';

        $path = $this->config['path'];

        $this->fullPath = $path . "/" . $fileName;

        $file = fopen($this->fullPath, 'w');

        fwrite($file, $json);
        fclose($file);
    }

    public function addDirectoryToPostmanStructrue(): void
    {
        $this->structure['item'] = $this->convertToPostmanItem($this->directory);
    }

    public function directoryExists(string $routeUri): bool
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

    public function convertToPostmanItem($directory)
    {
        $items = [];
        foreach ($directory as $name => $item) {
            if(array_key_exists('item', $item)){
                $items[] = [
                    'name' => $item['name'],
                    'item' => $this->convertToPostmanItem($item['item'])
                ];
            }
            else{
                $items[] = $item;
            }
        }
        return $items;
    }

    public function createDirectory(string $routeUri): array
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

    public function getDirectory(string $routeUri): array
    {
        foreach ($this->directory as $directory) {
            if ($directory['name'] == $routeUri) {
                return $directory['item'];
            }
        }
    }

    public function addToDirectory(string $routeUri, array $item, $directoryPath = null): void
    {
        $parts = explode('/', $routeUri);

        $current = &$this->directory;

        foreach ($parts as $part) {
            $current = &$current[$part]['item'];
        }

        $current[] = $item;
    }
}
