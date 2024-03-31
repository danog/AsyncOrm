<?php declare(strict_types=1);

use Amp\Mysql\MysqlConfig;
use Amp\Postgres\PostgresConfig;
use Amp\Redis\RedisConfig;
use danog\AsyncOrm\DbObject;
use danog\AsyncOrm\FieldConfig;
use danog\AsyncOrm\KeyType;
use danog\AsyncOrm\Settings\MysqlSettings;
use danog\AsyncOrm\Settings\PostgresSettings;
use danog\AsyncOrm\Settings\RedisSettings;
use danog\AsyncOrm\ValueType;

require __DIR__ . '/../vendor/autoload.php';

$settings = new MysqlSettings(
    new MysqlConfig(
        host: "/var/run/mysqld/mysqld.sock",
        user: 'user',
        password: 'password',
        database: 'database'
    ),
    cacheTtl: 100
);
$settings = new PostgresSettings(
    new PostgresConfig(
        host: "127.0.0.1",
        user: "user",
        password: "password",
        database: "database"
    ),
    cacheTtl: 100
);
$settings = new RedisSettings(
    RedisConfig::fromUri("redis://127.0.0.1"),
    cacheTtl: 100
);

$fieldConfig = new FieldConfig(
    'tableName',
    $settings,
    KeyType::STRING,
    ValueType::OBJECT
);

$db = $fieldConfig->build();

class MyObject extends DbObject
{
    public function __construct(
        public readonly string $value
    ) {
    }
}

$db->set("a", new MyObject('v'));
$obj = $db->get("a");
var_dump($obj->value);
