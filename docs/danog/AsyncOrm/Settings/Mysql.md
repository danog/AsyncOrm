---
title: "danog\\AsyncOrm\\Settings\\Mysql: MySQL backend settings."
description: "MariaDb 10.2+ or Mysql 5.6+ required."

---
# `danog\AsyncOrm\Settings\Mysql`
[Back to index](../../../index.md)

> Author: Daniil Gentili <daniil@daniil.it>  
> Author: Alexander Pankratov <alexander@i-c-a.su>  
  

MySQL backend settings.  

MariaDb 10.2+ or Mysql 5.6+ required.


## Constants
* `danog\AsyncOrm\Settings\Mysql::DEFAULT_SQL_MAX_CONNECTIONS`: 

* `danog\AsyncOrm\Settings\Mysql::DEFAULT_SQL_IDLE_TIMEOUT`: 

* `danog\AsyncOrm\Settings\Mysql::DEFAULT_CACHE_TTL`: 

## Properties
* `$optimizeIfWastedMb`: `int<1, max>|null` 
* `$maxConnections`: `positive-int` 
* `$idleTimeout`: `positive-int` 
* `$config`: `\T` 
* `$serializer`: `\danog\AsyncOrm\Serializer` 
* `$cacheTtl`: `int<0, max>` For how long to keep records in memory after last read.

## Method list:
* [`__construct(\Amp\Mysql\MysqlConfig $config, ?\danog\AsyncOrm\Serializer $serializer = NULL, int<\0, \max> $cacheTtl = \self::DEFAULT_CACHE_TTL, int<\1, \max> $maxConnections = \self::DEFAULT_SQL_MAX_CONNECTIONS, int<\1, \max> $idleTimeout = \self::DEFAULT_SQL_IDLE_TIMEOUT, ?int $optimizeIfWastedMb = NULL)`](#__construct-amp-mysql-mysqlconfig-config-danog-asyncorm-serializer-serializer-null-int-0-max-cachettl-self-default_cache_ttl-int-1-max-maxconnections-self-default_sql_max_connections-int-1-max-idletimeout-self-default_sql_idle_timeout-int-optimizeifwastedmb-null)
* [`getDriverClass(): string`](#getdriverclass-string)

## Methods:
### `__construct(\Amp\Mysql\MysqlConfig $config, ?\danog\AsyncOrm\Serializer $serializer = NULL, int<\0, \max> $cacheTtl = \self::DEFAULT_CACHE_TTL, int<\1, \max> $maxConnections = \self::DEFAULT_SQL_MAX_CONNECTIONS, int<\1, \max> $idleTimeout = \self::DEFAULT_SQL_IDLE_TIMEOUT, ?int $optimizeIfWastedMb = NULL)`




Parameters:

* `$config`: `\Amp\Mysql\MysqlConfig`   
* `$serializer`: `?\danog\AsyncOrm\Serializer` to use for object and mixed type values, if null defaults to either Igbinary or Native.  
* `$cacheTtl`: `int<\0, \max>` Cache TTL in seconds, if 0 disables caching.  
* `$maxConnections`: `int<\1, \max>` Maximum connection limit  
* `$idleTimeout`: `int<\1, \max>` Idle timeout  
* `$optimizeIfWastedMb`: `?int`   


#### See also: 
* `\Amp\Mysql\MysqlConfig`
* [`\danog\AsyncOrm\Serializer`: Serializer interface.](../../../danog/AsyncOrm/Serializer.md)
* `\max`




### `getDriverClass(): string`





---
Generated by [danog/phpdoc](https://phpdoc.daniil.it)