<?php

namespace Appwrite\Platform;

use Utopia\Database\Database;
use Utopia\Platform\Action as PlatformAction;
use Exception;
use InfluxDB\Database as InfluxDatabase;
use Utopia\App;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Registry\Registry;
use Utopia\Logger\Log;
use Throwable;

abstract class Action extends PlatformAction
{
    protected function logError(Registry $register, Throwable $error, string $action = 'syncUsageStats')
    {
        $logger = $register->get('logger');

        if ($logger) {
            $version = App::getEnv('_APP_VERSION', 'UNKNOWN');

            $log = new Log();
            $log->setNamespace("usage");
            $log->setServer(\gethostname());
            $log->setVersion($version);
            $log->setType(Log::TYPE_ERROR);
            $log->setMessage($error->getMessage());

            $log->addTag('code', $error->getCode());
            $log->addTag('verboseType', get_class($error));

            $log->addExtra('file', $error->getFile());
            $log->addExtra('line', $error->getLine());
            $log->addExtra('trace', $error->getTraceAsString());
            $log->addExtra('detailedTrace', $error->getTrace());

            $log->setAction($action);

            $isProduction = App::getEnv('_APP_ENV', 'development') === 'production';
            $log->setEnvironment($isProduction ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

            $responseCode = $logger->addLog($log);
            Console::info('Usage stats log pushed with status code: ' . $responseCode);
        }

        Console::warning("Failed: {$error->getMessage()}");
        Console::warning($error->getTraceAsString());
    }

    protected function getDatabase(Registry &$register, string $namespace): Database
    {
        $attempts = 0;

        do {
            try {
                $attempts++;

                $db = $register->get('db');
                $redis = $register->get('cache');

                $cache = new Cache(new RedisCache($redis));
                $database = new Database(new MariaDB($db), $cache);
                $database->setDefaultDatabase(App::getEnv('_APP_DB_SCHEMA', 'appwrite'));
                $database->setNamespace($namespace);

                if (!$database->exists($database->getDefaultDatabase(), 'projects')) {
                    throw new Exception('Projects collection not ready');
                }
                break; // leave loop if successful
            } catch (\Exception$e) {
                Console::warning("Database not ready. Retrying connection ({$attempts})...");
                if ($attempts >= DATABASE_RECONNECT_MAX_ATTEMPTS) {
                    throw new \Exception('Failed to connect to database: ' . $e->getMessage());
                }
                sleep(DATABASE_RECONNECT_SLEEP);
            }
        } while ($attempts < DATABASE_RECONNECT_MAX_ATTEMPTS);

        return $database;
    }

    protected function getInfluxDB(Registry &$register): InfluxDatabase
    {
        /** @var InfluxDB\Client $client */
        $client = $register->get('influxdb');
        $attempts = 0;
        $max = 10;
        $sleep = 1;

        do { // check if telegraf database is ready
            try {
                $attempts++;
                $database = $client->selectDB('telegraf');
                if (in_array('telegraf', $client->listDatabases())) {
                    break; // leave the do-while if successful
                }
            } catch (\Throwable$th) {
                Console::warning("InfluxDB not ready. Retrying connection ({$attempts})...");
                if ($attempts >= $max) {
                    throw new \Exception('InfluxDB database not ready yet');
                }
                sleep($sleep);
            }
        } while ($attempts < $max);
        return $database;
    }
}
