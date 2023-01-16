<?php

require_once __DIR__ . '/../worker.php';

use Utopia\Queue\Message;
use Appwrite\Event\Event;
use Appwrite\Event\Func;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Usage\Stats;
use Appwrite\Utopia\Response\Model\Execution;
use Domnikl\Statsd\Client;
use Executor\Executor;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\ID;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Queue\Server;

Authorization::disable();
Authorization::setDefaultStatus(false);

Server::setResource('execute', function () {
    return function (
        Func $queueForFunctions,
        Database $dbForProject,
        Client $statsd,
        Document $project,
        Document $function,
        string $trigger,
        string $data = null,
        ?Document $user = null,
        string $jwt = null,
        string $event = null,
        string $eventData = null,
        string $executionId = null,
    ) {
        $user ??= new Document();
        $functionId = $function->getId();
        $deploymentId = $function->getAttribute('deployment', '');

        /** Check if deployment exists */
        $deployment = $dbForProject->getDocument('deployments', $deploymentId);

        if ($deployment->getAttribute('resourceId') !== $functionId) {
            throw new Exception('Deployment not found. Create deployment before trying to execute a function');
        }

        if ($deployment->isEmpty()) {
            throw new Exception('Deployment not found. Create deployment before trying to execute a function');
        }

        /** Check if build has exists */
        $build = $dbForProject->getDocument('builds', $deployment->getAttribute('buildId', ''));
        if ($build->isEmpty()) {
            throw new Exception('Build not found');
        }

        if ($build->getAttribute('status') !== 'ready') {
            throw new Exception('Build not ready');
        }

        /** Check if  runtime is supported */
        $runtimes = Config::getParam('runtimes', []);

        if (!\array_key_exists($function->getAttribute('runtime'), $runtimes)) {
            throw new Exception('Runtime "' . $function->getAttribute('runtime', '') . '" is not supported');
        }

        $runtime = $runtimes[$function->getAttribute('runtime')];

        /** Create execution or update execution status */
        $execution = $dbForProject->getDocument('executions', $executionId ?? '');
        if ($execution->isEmpty()) {
            $executionId = ID::unique();
            $execution = $dbForProject->createDocument('executions', new Document([
                '$id' => $executionId,
                '$permissions' => $user->isEmpty() ? [] : [Permission::read(Role::user($user->getId()))],
                'functionId' => $functionId,
                'deploymentId' => $deploymentId,
                'trigger' => $trigger,
                'status' => 'waiting',
                'statusCode' => 0,
                'response' => '',
                'stderr' => '',
                'duration' => 0.0,
                'search' => implode(' ', [$functionId, $executionId]),
            ]));

            // TODO: @Meldiron Trigger executions.create event here

            if ($execution->isEmpty()) {
                throw new Exception('Failed to create or read execution');
            }
        }

        $execution->setAttribute('status', 'processing');
        $execution = $dbForProject->updateDocument('executions', $executionId, $execution);

        $vars = array_reduce($function->getAttribute('vars', []), function (array $carry, Document $var) {
            $carry[$var->getAttribute('key')] = $var->getAttribute('value');
            return $carry;
        }, []);

        /** Collect environment variables */
        $vars = \array_merge($vars, [
            'APPWRITE_FUNCTION_ID' => $functionId,
            'APPWRITE_FUNCTION_NAME' => $function->getAttribute('name'),
            'APPWRITE_FUNCTION_DEPLOYMENT' => $deploymentId,
            'APPWRITE_FUNCTION_TRIGGER' => $trigger,
            'APPWRITE_FUNCTION_PROJECT_ID' => $project->getId(),
            'APPWRITE_FUNCTION_RUNTIME_NAME' => $runtime['name'] ?? '',
            'APPWRITE_FUNCTION_RUNTIME_VERSION' => $runtime['version'] ?? '',
            'APPWRITE_FUNCTION_EVENT' => $event ?? '',
            'APPWRITE_FUNCTION_EVENT_DATA' => $eventData ?? '',
            'APPWRITE_FUNCTION_DATA' => $data ?? '',
            'APPWRITE_FUNCTION_USER_ID' => $user->getId() ?? '',
            'APPWRITE_FUNCTION_JWT' => $jwt ?? '',
        ]);

        /** Execute function */
        try {
            $client = new Executor(App::getEnv('_APP_EXECUTOR_HOST'));
            $executionResponse = $client->createExecution(
                projectId: $project->getId(),
                deploymentId: $deploymentId,
                payload: $vars['APPWRITE_FUNCTION_DATA'] ?? '',
                variables: $vars,
                timeout: $function->getAttribute('timeout', 0),
                image: $runtime['image'],
                source: $build->getAttribute('path', ''),
                entrypoint: $deployment->getAttribute('entrypoint', ''),
            );

            /** Update execution status */
            $execution
                ->setAttribute('status', $executionResponse['status'])
                ->setAttribute('statusCode', $executionResponse['statusCode'])
                ->setAttribute('response', $executionResponse['response'])
                ->setAttribute('stdout', $executionResponse['stdout'])
                ->setAttribute('stderr', $executionResponse['stderr'])
                ->setAttribute('duration', $executionResponse['duration']);
        } catch (\Throwable $th) {
            $interval = (new \DateTime())->diff(new \DateTime($execution->getCreatedAt()));
            $execution
                ->setAttribute('duration', (float)$interval->format('%s.%f'))
                ->setAttribute('status', 'failed')
                ->setAttribute('statusCode', $th->getCode())
                ->setAttribute('stderr', $th->getMessage());

            Console::error($th->getTraceAsString());
            Console::error($th->getFile());
            Console::error($th->getLine());
            Console::error($th->getMessage());
        }

        $execution = $dbForProject->updateDocument('executions', $executionId, $execution);

        /** Trigger Webhook */
        $executionModel = new Execution();
        $executionUpdate = new Event(Event::WEBHOOK_QUEUE_NAME, Event::WEBHOOK_CLASS_NAME);
        $executionUpdate
            ->setProject($project)
            ->setUser($user)
            ->setEvent('functions.[functionId].executions.[executionId].update')
            ->setParam('functionId', $function->getId())
            ->setParam('executionId', $execution->getId())
            ->setPayload($execution->getArrayCopy(array_keys($executionModel->getRules())))
            ->trigger();

        /** Trigger Functions */
        $queueForFunctions
            ->from($executionUpdate)
            ->trigger();

        /** Trigger realtime event */
        $allEvents = Event::generateEvents('functions.[functionId].executions.[executionId].update', [
            'functionId' => $function->getId(),
            'executionId' => $execution->getId()
        ]);
        $target = Realtime::fromPayload(
            // Pass first, most verbose event pattern
            event: $allEvents[0],
            payload: $execution
        );
        Realtime::send(
            projectId: 'console',
            payload: $execution->getArrayCopy(),
            events: $allEvents,
            channels: $target['channels'],
            roles: $target['roles']
        );
        Realtime::send(
            projectId: $project->getId(),
            payload: $execution->getArrayCopy(),
            events: $allEvents,
            channels: $target['channels'],
            roles: $target['roles']
        );

        /** Update usage stats */
        if (App::getEnv('_APP_USAGE_STATS', 'enabled') === 'enabled') {
            $usage = new Stats($statsd);
            $usage
                ->setParam('projectId', $project->getId())
                ->setParam('projectInternalId', $project->getInternalId())
                ->setParam('functionId', $function->getId()) // TODO: We should use functionInternalId in usage stats
                ->setParam('executions.{scope}.compute', 1)
                ->setParam('executionStatus', $execution->getAttribute('status', ''))
                ->setParam('executionTime', $execution->getAttribute('duration'))
                ->setParam('networkRequestSize', 0)
                ->setParam('networkResponseSize', 0)
                ->submit();
        }
    };
});

$server->job()
    ->inject('message')
    ->inject('dbForProject')
    ->inject('queueForFunctions')
    ->inject('statsd')
    ->inject('execute')
    ->action(function (Message $message, Database $dbForProject, Func $queueForFunctions, Client $statsd, callable $execute) {
        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        $events = $payload['events'] ?? [];
        $eventData = $payload['payload'] ?? '';
        $project = new Document($payload['project'] ?? []);
        $function = new Document($payload['function'] ?? []);
        $user = new Document($payload['user'] ?? []);

        if ($project->getId() === 'console') {
            return;
        }

        if (!empty($events)) {
            $limit = 30;
            $sum = 30;
            $offset = 0;
            $functions = [];
            /** @var Document[] $functions */
            while ($sum >= $limit) {
                $functions = $dbForProject->find('functions', [
                    Query::limit($limit),
                    Query::offset($offset),
                    Query::orderAsc('name'),
                ]);

                $sum = \count($functions);
                $offset = $offset + $limit;

                Console::log('Fetched ' . $sum . ' functions...');

                foreach ($functions as $function) {
                    if (!array_intersect($events, $function->getAttribute('events', []))) {
                        continue;
                    }
                    Console::success('Iterating function: ' . $function->getAttribute('name'));
                    $execute(
                        statsd: $statsd,
                        dbForProject: $dbForProject,
                        project: $project,
                        function: $function,
                        queueForFunctions: $queueForFunctions,
                        trigger: 'event',
                        event: $events[0],
                        eventData: \is_string($eventData) ? $eventData : \json_encode($eventData),
                        user: $user->isEmpty() ? null : $user,
                        data: null,
                        executionId: null,
                        jwt: null
                    );
                    Console::success('Triggered function: ' . $events[0]);
                }
            }
            return;
        }

        $type = $payload['type'] ?? '';
        $jwt = $payload['jwt'] ?? null;
        $data = $payload['data'] ?? null;
        $execution = new Document($payload['execution'] ?? []);

        switch ($type) {
            case 'http':
            case 'schedule':
                $execute(
                    project: $project,
                    function: $function,
                    dbForProject: $dbForProject,
                    queueForFunctions: $queueForFunctions,
                    trigger: $type,
                    executionId: $execution->isEmpty() ? null : $execution->getId(),
                    user: $user->isEmpty() ? null : $user,
                    data: $data,
                    jwt: $jwt,
                    statsd: $statsd,
                    event: null,
                    eventData: null,
                );
                break;
            default:
                throw new \Exception('Invalid build type');
                break;
        }
    });

$server->workerStart();
$server->start();
