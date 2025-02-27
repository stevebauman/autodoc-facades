<h1 align="center">Autodoc Facades</h1>

<p align="center">
A facade documenter for your Laravel application.
</p>

<p align="center">
<a href="https://github.com/stevebauman/autodoc-facades/actions" target="_blank"><img src="https://img.shields.io/github/actions/workflow/status/stevebauman/autodoc-facades/run-tests.yml?branch=master&style=flat-square"/></a>
<a href="https://packagist.org/packages/stevebauman/autodoc-facades" target="_blank"><img src="https://img.shields.io/packagist/v/stevebauman/autodoc-facades.svg?style=flat-square"/></a>
<a href="https://packagist.org/packages/stevebauman/autodoc-facades" target="_blank"><img src="https://img.shields.io/packagist/dt/stevebauman/autodoc-facades.svg?style=flat-square"/></a>
<a href="https://packagist.org/packages/stevebauman/autodoc-facades" target="_blank"><img src="https://img.shields.io/packagist/l/stevebauman/autodoc-facades.svg?style=flat-square"/></a>
</p>

---

Autodoc Facades uses the official Laravel [Facade Documenter](https://github.com/laravel/facade-documenter) to easily generate doc annotations for your application's Laravel facades inside your `app` directory using the `@see` annotation with a single command:

```php
php artisan autodoc:facades app
```

**Before**:

```php
namespace App\Facades;

/**
 * @see \App\Services\ServiceManager
 */
class Service extends Facade
{
    // ...
}
```

```php
namespace App\Services;

class ServiceManager
{
    public function all(string $param): array
    {
        // ...    
    }
}
```

**After**:

```diff
namespace App\Facades;

/**
+* @method static array all(string $param)
+* 
 * @see \App\Services\ServiceManager
 */
class Service extends Facade
{
    // ...
}
```

## Installation

Install via composer:

```bash
composer require --dev stevebauman/autodoc-facades
```

## Usage

Inside the terminal:

```bash
php artisan autodoc:facades {paths} {--only=} {--except=}
```

Inside a Laravel command:

```php
namespace App\Console\Commands;

class GenerateFacadeDocs extends Command
{
    // ...

    public function handle(): int
    {
        return $this->call('autodoc:facades', [
            'paths' => ['app'],
            '--except' => ['...'],
            '--only' => ['...'],
        ]);
    }
}
```

### Getting started

To begin, your facades must contain an `@see` annotation with the **fully-qualified namespace**.

It will not resolve short-name classnames of classes that were imported.

For example, **this will not work**:

```php
namespace App\Facades;

use App\Services\ServiceManager;

/**
 * @see ServiceManager
 */
class Service extends Facade
{
    // ...
}
```

If the underlying class forwards calls to another class, add a `@mixin` annotation to the underlying class so it is picked up by the documenter:

```php
namespace App\Facades;

use App\Services\ServiceManager;

/**
 * @see \App\Services\ServiceManager
 */
class Service extends Facade
{
    protected function getFacadeAccessor(): string
    {
        return ServiceManager::class
    }
}
```

```php
namespace App\Services;

use Illuminate\Support\Traits\ForwardsCalls;

/**
 * @mixin \App\Services\SomeClass
 */
class ServiceManager
{
    use ForwardsCalls;
    
    // ...
}
```

### Generating annotations in path

To generate doc annotations for all facades in your `app` directory, supply "app" as the path:

> All paths you provide that do not start with a directory separator will use the commands current working directory as the base path.

```bash
php artisan autodoc:facades app
```

### Generating annotations in many paths

Space separate paths to generate annotations for facades in those directories:

```bash
php artisan autodoc:facades app/Services/Facades app/Api/Facades
```

### Generating annotations for specific facades

Specify "only" classes to generate annotations only for those given:

> You may provide multiple "only" classes by space separating them.

```bash
php artisan autodoc:facades app --only App\Facades\Service
```

### Generating annotations for except specific facades

Specify "except" classes to generate annotations for all facades, except for those given:

> You may provide multiple "except" classes by space separating them.

```bash
php artisan autodoc:facades app --except App\Facades\Service
```
