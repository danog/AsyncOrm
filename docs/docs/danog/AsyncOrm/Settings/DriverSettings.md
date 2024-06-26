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
* `$serializer`: `danog\AsyncOrm\Serializer` 
* `$cacheTtl`: `int<0, max>` For how long to keep records in memory after last read.

## Method list:
* [`__construct(?\danog\AsyncOrm\Serializer $serializer = NULL, int $cacheTtl = \self::DEFAULT_CACHE_TTL)`](#__construct)
* [`getDriverClass(): class-string<\danog\AsyncOrm\DbArray>`](#getDriverClass)

## Methods:
### <a name="__construct"></a> `__construct(?\danog\AsyncOrm\Serializer $serializer = NULL, int $cacheTtl = \self::DEFAULT_CACHE_TTL)`




Parameters:

* `$serializer`: `?\danog\AsyncOrm\Serializer` to use for object and mixed type values, if null defaults to either Igbinary or Native.  
* `$cacheTtl`: `int`   


#### See also: 
* [`\danog\AsyncOrm\Serializer`: Serializer interface.](../../../danog/AsyncOrm/Serializer.md)




### <a name="getDriverClass"></a> `getDriverClass(): class-string<\danog\AsyncOrm\DbArray>`




#### See also: 
* [`\danog\AsyncOrm\DbArray`: DB array interface.](../../../danog/AsyncOrm/DbArray.md)




---
Generated by [danog/phpdoc](https://phpdoc.daniil.it)
