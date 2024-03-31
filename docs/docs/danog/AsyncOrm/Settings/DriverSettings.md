---
title: "danog\\AsyncOrm\\Settings\\DriverSettings: Base settings class for database backends."
description: ""

---
# `danog\AsyncOrm\Settings\DriverSettings`
[Back to index](../../../index.md)

> Author: Daniil Gentili <daniil@daniil.it>  
> Author: Alexander Pankratov <alexander@i-c-a.su>  
  

Base settings class for database backends.  




## Constants
* `danog\AsyncOrm\Settings\DriverSettings::DEFAULT_CACHE_TTL`: 

## Properties
* `$serializer`: `\danog\AsyncOrm\Serializer` 
* `$cacheTtl`: `int<0, max>` For how long to keep records in memory after last read.

## Method list:
* [`__construct(?\danog\AsyncOrm\Serializer $serializer = NULL, int $cacheTtl = \self::DEFAULT_CACHE_TTL)`](#__construct-danog-asyncorm-serializer-serializer-null-int-cachettl-self-default_cache_ttl)
* [`getDriverClass(): class-string<\danog\AsyncOrm\DbArray>`](#getdriverclass-class-string-danog-asyncorm-dbarray)

## Methods:
### `__construct(?\danog\AsyncOrm\Serializer $serializer = NULL, int $cacheTtl = \self::DEFAULT_CACHE_TTL)`




Parameters:

* `$serializer`: `?\danog\AsyncOrm\Serializer` to use for object and mixed type values, if null defaults to either Igbinary or Native.  
* `$cacheTtl`: `int`   


#### See also: 
* [`\danog\AsyncOrm\Serializer`: Serializer interface.](../../../danog/AsyncOrm/Serializer.md)




### `getDriverClass(): class-string<\danog\AsyncOrm\DbArray>`




#### See also: 
* [`\danog\AsyncOrm\DbArray`: DB array interface.](../../../danog/AsyncOrm/DbArray.md)




---
Generated by [danog/phpdoc](https://phpdoc.daniil.it)