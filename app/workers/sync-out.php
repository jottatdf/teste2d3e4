<?php

require_once __DIR__ . '/../worker.php';

use Ahc\Jwt\JWT;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use Swoole\Timer;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization;
use Utopia\Database\Exception\Structure;
use Utopia\Queue\Message;

$regions = array_filter(
    Config::getParam('regions', []),
    fn ($region) => App::getEnv('_APP_REGION') !== $region
        && $region !== 'default',
    ARRAY_FILTER_USE_KEY
);

$stack = [
    'regions' => $regions,
    'keys' => [],
];
$failures = [];

const CHUNK_MAX_KEYS = 500;
const MAX_CURL_SEND_ATTEMPTS = 4;

/**
 * @param string $url
 * @param string $token
 * @param array $payload
 * @return int
 */
function call(string $url, string $token, array $payload): int
{

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    for ($attempts = 0; $attempts < MAX_CURL_SEND_ATTEMPTS; $attempts++) {
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($status === 200) {
            return $status;
        }

        sleep(1);
    }

    curl_close($ch);

    return $status;
}

/**
 * @throws Authorization
 * @throws Structure
 * @throws Exception|\Exception
 */
function handle($dbForConsole, $regions, $payload): void
{

    $jwt = new JWT(App::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 600, 10);
    $token = $jwt->encode([]);

    foreach ($regions as $code => $region) {
        $time = DateTime::now();
        $status = call($region['domain'] . '/v1/edge/sync', $token, ['keys' => $payload]);
        if ($status !== Response::STATUS_CODE_OK) {
            Console::error("[{$time}] Request to {$code} has failed");

            foreach ($payload as $sync) {
                $dbForConsole->createDocument('syncs', new Document([
                    'region' => App::getEnv('_APP_REGION'),
                    'target' => $code,
                    'type' => $sync['type'],
                    'key'  => ['key' => $sync['key']],
                    'status' => $status,
                ]));
            }
        }
    }
}

$server->job()
    ->inject('message')
    ->action(function (Message $message) use (&$stack, &$failures) {

        $payload = $message->getPayload() ?? [];

        //Get failed requests
        if (!empty($payload['region']) && !empty($payload['keys'])) {
            $regions = array_filter(
                Config::getParam('regions', []),
                fn ($region) => $payload['region']  === $region,
                ARRAY_FILTER_USE_KEY
            );

            $failures[] = [
                'regions' => $regions,
                'keys' => $payload['keys']
            ];

            return;
        }

        if (empty($payload['type'])) {
            return;
        }

        if (!empty($payload['key'])) {
                $stack['keys'][] = [
                    'type' => $payload['type'],
                    'key'  => $payload['key'],
                ];
        }
    });

$server
    ->workerStart()
    ->inject('dbForConsole')
    ->action(function ($dbForConsole) use (&$stack, &$failures) {

        Timer::tick(5000, function () use ($dbForConsole, &$stack, &$failures) {
            $time = DateTime::now();

            if (empty($stack['keys']) && count($failures) === 0) {
                Console::info("[{$time}] Stack is empty");
                return;
            }

            //Send failed requests
            if (count($failures) > 0) {
                $i = 0;
                while ($i < count($failures)) {
                    $failure = array_shift($failures);
                    Console::info("[{$time}] ReSending " . count($failure['keys']) . " to " . key($failure['regions']));
                    handle($dbForConsole, $failure['regions'], $failure['keys']);
                    $i++;
                }
                return;
            }

            $chunk = array_slice($stack['keys'], 0, CHUNK_MAX_KEYS, true);
            array_splice($stack['keys'], 0, CHUNK_MAX_KEYS);
            Console::log("[{$time}] Sending " . count($chunk) . " remains " . count($stack['keys']));
            handle($dbForConsole, $stack['regions'], $chunk);
        });
        Console::success("Out  [" . App::getEnv('_APP_REGION') . "] edge cache purging worker Started");
    });

  $server->start();
