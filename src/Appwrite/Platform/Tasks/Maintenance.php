<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Enum\DeleteType;
use Appwrite\Event\Certificate;
use Appwrite\Event\Delete;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\DateTime;
use Utopia\Database\Query;
use Utopia\Platform\Action;

class Maintenance extends Action
{
    public static function getName(): string
    {
        return 'maintenance';
    }

    public function __construct()
    {
        $this
            ->desc('Schedules maintenance tasks and publishes them to our queues')
            ->inject('dbForConsole')
            ->inject('queueForCertificates')
            ->inject('queueForDeletes')
            ->callback(fn (Database $dbForConsole, Certificate $queueForCertificates, Delete $queueForDeletes) => $this->action($dbForConsole, $queueForCertificates, $queueForDeletes));
    }

    public function action(Database $dbForConsole, Certificate $queueForCertificates, Delete $queueForDeletes): void
    {
        Console::title('Maintenance V1');
        Console::success(APP_NAME . ' maintenance process v1 has started');

        // # of days in seconds (1 day = 86400s)
        $interval = (int) App::getEnv('_APP_MAINTENANCE_INTERVAL', '86400');
        $executionLogsRetention = (int) App::getEnv('_APP_MAINTENANCE_RETENTION_EXECUTION', '1209600');
        $auditLogRetention = (int) App::getEnv('_APP_MAINTENANCE_RETENTION_AUDIT', '1209600');
        $abuseLogsRetention = (int) App::getEnv('_APP_MAINTENANCE_RETENTION_ABUSE', '86400');
        $usageStatsRetentionHourly = (int) App::getEnv('_APP_MAINTENANCE_RETENTION_USAGE_HOURLY', '8640000'); //100 days
        $cacheRetention = (int) App::getEnv('_APP_MAINTENANCE_RETENTION_CACHE', '2592000'); // 30 days
        $schedulesDeletionRetention = (int) App::getEnv('_APP_MAINTENANCE_RETENTION_SCHEDULES', '86400'); // 1 Day

        Console::loop(function () use ($interval, $executionLogsRetention, $abuseLogsRetention, $auditLogRetention, $cacheRetention, $schedulesDeletionRetention, $usageStatsRetentionHourly, $dbForConsole, $queueForDeletes, $queueForCertificates) {
            $time = DateTime::now();

            Console::info("[{$time}] Notifying workers with maintenance tasks every {$interval} seconds");
            $this->notifyDeleteExecutionLogs($executionLogsRetention, $queueForDeletes);
            $this->notifyDeleteAbuseLogs($abuseLogsRetention, $queueForDeletes);
            $this->notifyDeleteAuditLogs($auditLogRetention, $queueForDeletes);
            $this->notifyDeleteUsageStats($usageStatsRetentionHourly, $queueForDeletes);
            $this->notifyDeleteConnections($queueForDeletes);
            $this->notifyDeleteExpiredSessions($queueForDeletes);
            $this->renewCertificates($dbForConsole, $queueForCertificates);
            $this->notifyDeleteCache($cacheRetention, $queueForDeletes);
            $this->notifyDeleteSchedules($schedulesDeletionRetention, $queueForDeletes);
        }, $interval);
    }

    private function notifyDeleteExecutionLogs(int $interval, Delete $queueForDeletes): void
    {
        ($queueForDeletes)
            ->setType(DeleteType::Executions->value)
            ->setDatetime(DateTime::addSeconds(new \DateTime(), -1 * $interval))
            ->trigger();
    }

    private function notifyDeleteAbuseLogs(int $interval, Delete $queueForDeletes): void
    {
        ($queueForDeletes)
            ->setType(DeleteType::Abuse->value)
            ->setDatetime(DateTime::addSeconds(new \DateTime(), -1 * $interval))
            ->trigger();
    }

    private function notifyDeleteAuditLogs(int $interval, Delete $queueForDeletes): void
    {
        ($queueForDeletes)
            ->setType(DeleteType::Audit->value)
            ->setDatetime(DateTime::addSeconds(new \DateTime(), -1 * $interval))
            ->trigger();
    }

    private function notifyDeleteUsageStats(int $usageStatsRetentionHourly, Delete $queueForDeletes): void
    {
        ($queueForDeletes)
            ->setType(DeleteType::Usage->value)
            ->setUsageRetentionHourlyDateTime(DateTime::addSeconds(new \DateTime(), -1 * $usageStatsRetentionHourly))
            ->trigger();
    }

    private function notifyDeleteConnections(Delete $queueForDeletes): void
    {
        ($queueForDeletes)
            ->setType(DeleteType::Realtime->value)
            ->setDatetime(DateTime::addSeconds(new \DateTime(), -60))
            ->trigger();
    }

    private function notifyDeleteExpiredSessions(Delete $queueForDeletes): void
    {
        ($queueForDeletes)
            ->setType(DeleteType::Sessions->value)
            ->trigger();
    }

    private function renewCertificates(Database $dbForConsole, Certificate $queueForCertificate): void
    {
        $time = DateTime::now();

        $certificates = $dbForConsole->find('certificates', [
            Query::lessThan('attempts', 5), // Maximum 5 attempts
            Query::lessThanEqual('renewDate', $time), // includes 60 days cooldown (we have 30 days to renew)
            Query::limit(200), // Limit 200 comes from LetsEncrypt (300 orders per 3 hours, keeping some for new domains)
        ]);


        if (\count($certificates) > 0) {
            Console::info("[{$time}] Found " . \count($certificates) . " certificates for renewal, scheduling jobs.");

            foreach ($certificates as $certificate) {
                $queueForCertificate
                    ->setDomain(new Document([
                        'domain' => $certificate->getAttribute('domain')
                    ]))
                    ->trigger();
            }
        } else {
            Console::info("[{$time}] No certificates for renewal.");
        }
    }

    private function notifyDeleteCache($interval, Delete $queueForDeletes): void
    {

        ($queueForDeletes)
            ->setType(DeleteType::CacheByTimestamp->value)
            ->setDatetime(DateTime::addSeconds(new \DateTime(), -1 * $interval))
            ->trigger();
    }

    private function notifyDeleteSchedules($interval, Delete $queueForDeletes): void
    {

        ($queueForDeletes)
            ->setType(DeleteType::Schedules->value)
            ->setDatetime(DateTime::addSeconds(new \DateTime(), -1 * $interval))
            ->trigger();
    }
}
