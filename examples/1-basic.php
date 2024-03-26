<?php declare(strict_types=1);

use Amp\Mysql\MysqlConfig;
use danog\AsyncOrm\DbObject;
use danog\AsyncOrm\FieldConfig;
use danog\AsyncOrm\KeyType;
use danog\AsyncOrm\Settings\Mysql;
use danog\AsyncOrm\ValueType;

require __DIR__ . '/../vendor/autoload.php';

$settings = new Mysql(
    new MysqlConfig("/var/run/mysqld/mysqld.sock", 0, 'daniil', database: 'test'),
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
