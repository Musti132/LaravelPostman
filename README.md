# LaravelPostman
[![Generic badge](https://img.shields.io/badge/PHP-8.0%2B-green.svg)](#)

### Description
This package will take your Laravel Api Routes and turn them into a structured Postman collection v2.1 with fields

### Requirements
* Laravel 9+ (Only tested on laravel 9)

### Installation
Install via composer 

```bash
 composer require musti/laravel-postman
 ```
 Publish configuration file
```bash
php artisan vendor:publish --tag=laravel-postman-config
```

### Usage

Export routes
```php
php artisan laravel:export
```
The output path is defined in the configuration file


