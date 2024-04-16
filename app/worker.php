<?php

require_once __DIR__ . '/init.php';

use Appwrite\Event\Event;
use Appwrite\Event\Audit;
use Appwrite\Event\Build;
use Appwrite\Event\Certificate;
use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Delete;
use Appwrite\Event\Func;
use Appwrite\Event\Hamster;
use Appwrite\Event\Mail;
use Appwrite\Event\Migration;
use Appwrite\Event\Phone;
use Appwrite\Event\Usage;
use Appwrite\Event\UsageDump;
use Appwrite\Platform\Appwrite;
use Swoole\Runtime;
use Utopia\App;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Service;
use Utopia\Queue\Message;
use Utopia\Queue\Server;
use Utopia\Registry\Registry;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;
use Utopia\Pools\Group;
use Utopia\Queue\Connection;

Authorization::disable();
Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

Server::setResource('register', fn () => $register);

Server::setResource('dbForConsole', function (Cache $cache, Registry $register) {
    $pools = $register->get('pools');
    $database = $pools
        ->get('console')
        ->pop()
        ->getResource()
    ;

    $adapter = new Database($database, $cache);
    $adapter->setNamespace('_console');

    return $adapter;
}, ['cache', 'register']);

Server::setResource('dbForProject', function (Cache $cache, Registry $register, Message $message, Database $dbForConsole) {
    $payload = $message->getPayload() ?? [];
    $project = new Document($payload['project'] ?? []);

    if ($project->isEmpty() || $project->getId() === 'console') {
        return $dbForConsole;
    }

    $pools = $register->get('pools');
    $database = $pools
        ->get($project->getAttribute('database'))
        ->pop()
        ->getResource()
    ;

    $adapter = new Database($database, $cache);
    $adapter->setNamespace('_' . $project->getInternalId());
    return $adapter;
}, ['cache', 'register', 'message', 'dbForConsole']);

Server::setResource('project', function (Message $message, Database $dbForConsole) {
    $payload = $message->getPayload() ?? [];
    $project = new Document($payload['project'] ?? []);

    if ($project->getId() === 'console') {
        return $project;
    }
    return $dbForConsole->getDocument('projects', $project->getId());
    ;
}, ['message', 'dbForConsole']);

Server::setResource('getProjectDB', function (Group $pools, Database $dbForConsole, $cache) {
    $databases = []; // TODO: @Meldiron This should probably be responsibility of utopia-php/pools

    return function (Document $project) use ($pools, $dbForConsole, $cache, &$databases): Database {
        if ($project->isEmpty() || $project->getId() === 'console') {
            return $dbForConsole;
        }

        $databaseName = $project->getAttribute('database');

        if (isset($databases[$databaseName])) {
            $database = $databases[$databaseName];
            $database->setNamespace('_' . $project->getInternalId());
            return $database;
        }

        $dbAdapter = $pools
            ->get($databaseName)
            ->pop()
            ->getResource();

        $database = new Database($dbAdapter, $cache);

        $databases[$databaseName] = $database;

        $database->setNamespace('_' . $project->getInternalId());

        return $database;
    };
}, ['pools', 'dbForConsole', 'cache']);

Server::setResource('abuseRetention', function () {
    return DateTime::addSeconds(new \DateTime(), -1 * App::getEnv('_APP_MAINTENANCE_RETENTION_ABUSE', 86400));
});

Server::setResource('auditRetention', function () {
    return DateTime::addSeconds(new \DateTime(), -1 * App::getEnv('_APP_MAINTENANCE_RETENTION_AUDIT', 1209600));
});

Server::setResource('executionRetention', function () {
    return DateTime::addSeconds(new \DateTime(), -1 * App::getEnv('_APP_MAINTENANCE_RETENTION_EXECUTION', 1209600));
});

Server::setResource('cache', function (Registry $register) {
    $pools = $register->get('pools');
    $list = Config::getParam('pools-cache', []);
    $adapters = [];

    foreach ($list as $value) {
        $adapters[] = $pools
            ->get($value)
            ->pop()
            ->getResource()
        ;
    }

    return new Cache(new Sharding($adapters));
}, ['register']);
Server::setResource('log', fn() => new Log());
Server::setResource('queueForUsage', function (Connection $queue) {
    return new Usage($queue);
}, ['queue']);
Server::setResource('queueForUsageDump', function (Connection $queue) {
    return new UsageDump($queue);
}, ['queue']);
Server::setResource('queue', function (Group $pools) {
    return $pools->get('queue')->pop()->getResource();
}, ['pools']);
Server::setResource('queueForDatabase', function (Connection $queue) {
    return new EventDatabase($queue);
}, ['queue']);
Server::setResource('queueForMessaging', function (Connection $queue) {
    return new Phone($queue);
}, ['queue']);
Server::setResource('queueForMails', function (Connection $queue) {
    return new Mail($queue);
}, ['queue']);
Server::setResource('queueForBuilds', function (Connection $queue) {
    return new Build($queue);
}, ['queue']);
Server::setResource('queueForDeletes', function (Connection $queue) {
    return new Delete($queue);
}, ['queue']);
Server::setResource('queueForEvents', function (Connection $queue) {
    return new Event($queue);
}, ['queue']);
Server::setResource('queueForAudits', function (Connection $queue) {
    return new Audit($queue);
}, ['queue']);
Server::setResource('queueForFunctions', function (Connection $queue) {
    return new Func($queue);
}, ['queue']);
Server::setResource('queueForCertificates', function (Connection $queue) {
    return new Certificate($queue);
}, ['queue']);
Server::setResource('queueForMigrations', function (Connection $queue) {
    return new Migration($queue);
}, ['queue']);
Server::setResource('logger', function (Registry $register) {
    return $register->get('logger');
}, ['register']);
Server::setResource('pools', function (Registry $register) {
    return $register->get('pools');
}, ['register']);
Server::setResource('getFunctionsDevice', function () {
    return function (string $projectId) {
        return getDevice(APP_STORAGE_FUNCTIONS . '/app-' . $projectId);
    };
});
Server::setResource('getFilesDevice', function () {
    return function (string $projectId) {
        return getDevice(APP_STORAGE_UPLOADS . '/app-' . $projectId);
    };
});
Server::setResource('getBuildsDevice', function () {
    return function (string $projectId) {
        return getDevice(APP_STORAGE_BUILDS . '/app-' . $projectId);
    };
});
Server::setResource('getCacheDevice', function () {
    return function (string $projectId) {
        return getDevice(APP_STORAGE_CACHE . '/app-' . $projectId);
    };
});

$pools = $register->get('pools');
$platform = new Appwrite();
$args = $_SERVER['argv'];

if (!isset($args[1])) {
    Console::error('Missing worker name');
    Console::exit(1);
}

\array_shift($args);
$workerName = $args[0];
$workerIndex = $args[1] ?? '';

if (!empty($workerIndex)) {
    $workerName .= '_' . $workerIndex;
}

try {
    /**
     * Any worker can be configured with the following env vars:
     * - _APP_WORKERS_NUM           The total number of worker processes
     * - _APP_WORKER_PER_CORE       The number of worker processes per core (ignored if _APP_WORKERS_NUM is set)
     */
    $platform->init(Service::TYPE_WORKER, [
        'workersNum' => App::getEnv('_APP_WORKERS_NUM', 1),
        'connection' => $pools->get('queue')->pop()->getResource(),
        'workerName' => strtolower($workerName) ?? null,
        'queueName' => 'v1-' . strtolower($workerName),
    ]);
} catch (\Exception $e) {
    Console::error($e->getMessage() . ', File: ' . $e->getFile() .  ', Line: ' . $e->getLine());
}

$worker = $platform->getWorker();

$worker
    ->shutdown()
    ->inject('pools')
    ->action(function (Group $pools) {
        $pools->reclaim();
    });

$worker
    ->error()
    ->inject('error')
    ->inject('logger')
    ->inject('log')
    ->inject('project')
    ->action(function (Throwable $error, ?Logger $logger, Log $log, Document $project) {
        $version = App::getEnv('_APP_VERSION', 'UNKNOWN');

        if ($error instanceof PDOException) {
            throw $error;
        }

        if ($logger && ($error->getCode() >= 500 || $error->getCode() === 0)) {
            $log->setNamespace("appwrite-worker");
            $log->setServer(\gethostname());
            $log->setVersion($version);
            $log->setType(Log::TYPE_ERROR);
            $log->setMessage($error->getMessage());
            $log->setAction('appwrite-queue-' . App::getEnv('QUEUE'));
            $log->addTag('verboseType', get_class($error));
            $log->addTag('code', $error->getCode());
            $log->addTag('projectId', $project->getId() ?? 'n/a');
            $log->addExtra('file', $error->getFile());
            $log->addExtra('line', $error->getLine());
            $log->addExtra('trace', $error->getTraceAsString());
            $log->addExtra('detailedTrace', $error->getTrace());
            $log->addExtra('roles', Authorization::getRoles());

            $isProduction = App::getEnv('_APP_ENV', 'development') === 'production';
            $log->setEnvironment($isProduction ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

            $responseCode = $logger->addLog($log);
            Console::info('Usage stats log pushed with status code: ' . $responseCode);
        }

        Console::error('[Error] Type: ' . get_class($error));
        Console::error('[Error] Message: ' . $error->getMessage());
        Console::error('[Error] File: ' . $error->getFile());
        Console::error('[Error] Line: ' . $error->getLine());
    });

$worker->workerStart()
    ->action(function () use ($workerName) {
        Console::info("Worker $workerName  started");
    });

$worker->start();
