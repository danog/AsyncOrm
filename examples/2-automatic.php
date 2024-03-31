<?php declare(strict_types=1);

use Amp\Mysql\MysqlConfig;
use Amp\Postgres\PostgresConfig;
use Amp\Redis\RedisConfig;
use danog\AsyncOrm\Annotations\OrmMappedArray;
use danog\AsyncOrm\DbArray;
use danog\AsyncOrm\DbAutoProperties;
use danog\AsyncOrm\FieldConfig;
use danog\AsyncOrm\KeyType;
use danog\AsyncOrm\Settings;
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

/**
 * Main class of your application.
 */
final class Application
{
    use DbAutoProperties;

    /**
     * This field is automatically connected to the database using the specified Settings.
     */
    #[OrmMappedArray(KeyType::STRING, ValueType::INT)]
    private DbArray $dbProperty;

    public function __construct(
        Settings $settings,
        string $tablePrefix
    ) {
        $this->initDbProperties($settings, $tablePrefix);
    }

    public function businessLogic(): void
    {
        $this->dbProperty['someKey'] = 123;
        var_dump($this->dbProperty['someKey']);
    }

    public function shutdown(): void
    {
        // Flush all database caches, saving all changes.
        $this->saveDbProperties();
    }
}

$app = new Application($settings, 'tablePrefix');
$app->businessLogic();
$app->shutdown();
