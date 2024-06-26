---
title: "danog\\AsyncOrm\\Annotations\\OrmMappedArray: Attribute use to autoconfigure ORM properties."
description: ""

---
# `danog\AsyncOrm\Annotations\OrmMappedArray`
[Back to index](../../../index.md)

> Author: Daniil Gentili <daniil@daniil.it>  
> Author: Alexander Pankratov <alexander@i-c-a.su>  
  

Attribute use to autoconfigure ORM properties.  



## Properties
* `$keyType`: `danog\AsyncOrm\KeyType` Key type.
* `$valueType`: `danog\AsyncOrm\ValueType` Value type.
* `$cacheTtl`: `(int<0, max> | null)` TTL of the cache, if null defaults to the value specified in the settings.

If zero disables caching.
* `$optimizeIfWastedMb`: `(int<1, max> | null)` Optimize table if more than this many megabytes are wasted, if null defaults to the value specified in the settings.
* `$tablePostfix`: `?string` Table name postfix, if null defaults to the property name.

## Method list:
* [`__construct(\danog\AsyncOrm\KeyType $keyType, \danog\AsyncOrm\ValueType $valueType, ?int $cacheTtl = NULL, ?int $optimizeIfWastedMb = NULL, ?string $tablePostfix = NULL)`](#__construct)

## Methods:
### <a name="__construct"></a> `__construct(\danog\AsyncOrm\KeyType $keyType, \danog\AsyncOrm\ValueType $valueType, ?int $cacheTtl = NULL, ?int $optimizeIfWastedMb = NULL, ?string $tablePostfix = NULL)`




Parameters:

* `$keyType`: `\danog\AsyncOrm\KeyType`   
* `$valueType`: `\danog\AsyncOrm\ValueType`   
* `$cacheTtl`: `?int`   
* `$optimizeIfWastedMb`: `?int`   
* `$tablePostfix`: `?string`   


#### See also: 
* [`\danog\AsyncOrm\KeyType`: Specifies the type of keys.](../../../danog/AsyncOrm/KeyType.md)
* [`\danog\AsyncOrm\ValueType`: Specifies the serializer to use when saving values.](../../../danog/AsyncOrm/ValueType.md)




---
Generated by [danog/phpdoc](https://phpdoc.daniil.it)
