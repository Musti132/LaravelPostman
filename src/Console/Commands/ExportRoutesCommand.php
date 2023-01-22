<?php

declare(strict_types=1);

namespace Musti\LaravelPostman\Console\Commands;

use Illuminate\Config\Repository;
use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
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
    protected array $ignoredRoutes = [
        '_ignition/health-check',
        '_ignition/update-config',
        '_ignition/execute-solution',
        '_debugbar',
        'sanctum/csrf-cookie',
    ];

    /**
     * Postman directory
     *
     * @var array
     */
    protected array $directory = [];
    protected $routeUri;

    public function __construct(Router $router, Repository $config)
    {
        parent::__construct();

        if (!empty($config['ignore_list']) && is_array($config['ignore_list'])) {
            $this->addToIgnoreList($config['ignore_list']);
        }

        $this->router = $router;
        $this->config = $config['laravel-postman'];
    }

    public function handle()
    {
        $this->createPostmanStructure();

        $routes = $this->router->getRoutes();

        foreach ($routes as $route) {
            if (in_array($route->uri, $this->ignoredRoutes)) {
                continue;
            }
            $method = $route->methods[0];

            if ($method == 'HEAD') {
                continue;
            }

            if ($route->getAction()['controller'] == null) {
                continue;
            }

            //Remove api/, versioning, trailing slash, parameters and end trailing slash
            $this->routeUri = preg_replace('/(api\/v[0-9]+\/|api\/|\/\{.*\}|\/$)/', '', $route->uri);

            //Only pluralize first part of uri if there is more than one part and return the uri as a whole
            $this->routeUri = Str::plural(explode('/', $this->routeUri)[0]) .
                (count(explode('/', $this->routeUri)) > 1
                    ? '/' . implode('/', array_slice(explode('/', $this->routeUri), 1))
                    : '');

            if (!$this->directoryExists($this->routeUri)) {
                $this->createDirectory($this->routeUri);
            }

            $item = $this->createItemFromRoute($route, $route->getAction()['controller']);

            $this->addToDirectory($this->routeUri, $item);
        }

        $this->addDirectoryToPostmanStructrue();

        $this->exportJson();
    }

    public function createItemFromRoute($route, $controller = null)
    {
        [$controller, $method] = explode('@', $controller);

        $name = $this->routeUri . " " . Str::ucfirst($method);

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

        if ($controller == null) {
            return $item;
        }

        $formdata = $this->checkIfControllerMethodHasFormRequest($controller, $method);

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
            $type = $parameter->getType();

            if ($type instanceof \ReflectionNamedType) {
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

        if ($formRequest instanceof FormRequest) {
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
                'name' => $this->config['app_name'],
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'item' => [],
        ];

        return $this->structure;
    }

    public function addItem(array $item): void
    {
        $this->structure['item'][] = $item;
    }

    public function addToIgnoreList(): void
    {
        $this->ignoredRoutes = array_merge($this->ignoredRoutes, $this->config['ignore_list']);
    }

    public function exportJson(): void
    {
        $json = json_encode($this->structure, JSON_PRETTY_PRINT);

        $file = fopen(storage_path('postman.json'), 'w');
        fwrite($file, $json);
        fclose($file);
    }

    public function addDirectoryToPostmanStructrue(): void
    {
        foreach ($this->directory as $directory) {
            $this->structure['item'] = $directory;
        }
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
        dd($directory);
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
                    'name' => $part,
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
        $current = array_values($current);
    }
}
