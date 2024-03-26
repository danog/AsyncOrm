<?php declare(strict_types=1);

use Amp\Mysql\MysqlConfig;
use danog\AsyncOrm\DbObject;
use danog\AsyncOrm\FieldConfig;
use danog\AsyncOrm\KeyType;
use danog\AsyncOrm\Serializer\Native;
use danog\AsyncOrm\Settings\Mysql;
use danog\AsyncOrm\ValueType;

require __DIR__ . '/../vendor/autoload.php';

$settings = new Mysql(
    new MysqlConfig("/var/run/mysqld/mysqld.sock", 0, 'daniil', database: 'test'),
    new Native,
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
}

$db->set("a", new MyObject);
$db->get("a")->save();
