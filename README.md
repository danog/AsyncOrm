# Async ORM

Async ORM based on amphp, created by Daniil Gentili <daniil@daniil.it> and Alexander Pankratov <alexander@i-c-a.su>.  

Supports MySQL, Redis, Postgres.  

Features read and write-back caching, type-specific optimizations, and much more!

## Installation

```bash
composer require danog/async-orm
```

## Usage

There are two main ways to use the ORM: through automatic ORM properties, which automatically connects appropriately marked `DbArray` properties to the specified database, or by manually instantiating a `DbArray` with a `DbArrayBuilder`.

* [Automatic ORM properties example &raquo;](https://github.com/danog/AsyncOrm/blob/master/examples/1-automatic.php)
* [Manual example &raquo;](https://github.com/danog/AsyncOrm/blob/master/examples/2-manual.php)

The `DbArray` obtained through one of the methods above is an abstract array object that automatically stores and fetches elements from the specified database.  

```php
/** @var DbArray<TKey, TValue> $arr */

$value = $arr[$key];
$arr[$key] = $newValue;

if (isset($arr[$otherKey])) {
    // Some logic
    unset($arr[$otherKey]);
}
```

## API Documentation

Click [here &raquo;](https://daniil.it/AsyncOrm/docs) to view the API documentation.
