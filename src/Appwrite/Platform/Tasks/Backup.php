<?php

namespace Appwrite\Platform\Tasks;

use Exception;
use PDO;
use Utopia\DSN\DSN;
use Utopia\Platform\Action;
use Utopia\App;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Database;
use Utopia\Storage\Device;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Device\Local;
use Utopia\Validator\Text;

class Backup extends Action
{
    public const BACKUPS_PATH = '/backups';
    public const BACKUP_INTERVAL_SECONDS = 60 * 60 * 4; // 4 hours;
    public const COMPRESS_ALGORITHM = 'zstd'; // https://www.percona.com/blog/get-your-backup-to-half-of-its-size-introducing-zstd-support-in-percona-xtrabackup/
    public const CLEANUP_LOCAL_FILES_SECONDS = 60 * 60 * 24 * 7;
    public const CLEANUP_CLOUD_FILES_SECONDS = 60 * 60 * 24 * 7;
    public const UPLOAD_CHUNK_SIZE = 5 * 1024 * 1024; // Must be greater than 5MB;
    public const RETRY_BACKUP = 1;
    public const RETRY_TAR = 1;
    public const RETRY_UPLOAD = 2;
    public const VERSION = 'v1';

    protected ?DSN $dsn = null;
    protected ?string $database = null;
    protected ?DOSpaces $s3 = null;
    protected string $xtrabackupContainerId;
    protected int $processors = 1;

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this
            ->desc('Backup a database')
            ->param('database', null, new Text(100), 'Database name, for example: db_fra1_03')
            ->inject('logError')
            ->callback(fn (string $database, callable $logError) => $this->action($database, $logError));
    }

    /**
     * @throws Exception
     */
    public function action(string $database, callable $logError): void
    {
        Console::title('Backups V1');
        Console::success(APP_NAME . ' Database Backup Process has started..');

        $this->database = $database;

        $this->checkEnvVariables();
        $this->dsn = $this->getDsn($database);
        if (is_null($this->dsn)) {
            throw new Exception('No DSN found for database ' . $database . '. Check the value of _APP_CONNECTIONS_DB_REPLICAS');
        }

        Console::info('Trying to connect to ' . $this->dsn->getHost());
        try {
            $this->retry(function () {
                $dsnHost = $this->dsn->getHost();
                $dsnPort = $this->dsn->getPort();
                $dsnUser = $this->dsn->getUser();
                $dsnPass = $this->dsn->getPassword();
                $dsnScheme = $this->dsn->getScheme();
                $dsnDatabase = $this->dsn->getPath();
                $pdo = new PDO("mysql:host={$dsnHost};port={$dsnPort};dbname={$dsnDatabase};charset=utf8mb4", $dsnUser, $dsnPass);

                $adapter = match ($dsnScheme) {
                    'mysql' => new MySQL($pdo),
                    'mariadb' => new MariaDB($pdo)
                };
                $database = new Database($adapter, new Cache(new None()));
                $database->ping();
                Console::success('Connected to ' . $dsnHost);
            }, 10, 5);
        } catch (Exception $error) {
            throw new Exception('Failed to connect to database: ' . $error->getMessage());
        }

        $storageDSN = new DSN(App::getEnv('_APP_CONNECTIONS_BACKUPS_STORAGE', ''));
        $this->s3 = new DOSpaces('/' . $database . '/' . self::VERSION, $storageDSN->getUser(), $storageDSN->getPassword(), $storageDSN->getPath(), $storageDSN->getParam('region'));

        $this->setContainerId();
        $this->setProcessors();

        $sleep = (int) App::getEnv('_APP_BACKUPS_INTERVAL', 0); // 120 seconds (by default)
        $jobInitTime = App::getEnv('_APP_BACKUPS_START_TIME'); // (hour:minutes)

        if (!$sleep || !$jobInitTime) {
            throw new Exception('Invalid backup interval or start time');
        }

        $now = new \DateTime();
        $now->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $next = new \DateTime($now->format("Y-m-d $jobInitTime"));
        $next->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $delay = $next->getTimestamp() - $now->getTimestamp();

        /**
         * If time passed for the target day.
         */
        if ($delay <= 0) {
            $next->add(\DateInterval::createFromDateString('1 days'));
            $delay = $next->getTimestamp() - $now->getTimestamp();
        }

        self::log('Setting loop start time to ' . $next->format("Y-m-d H:i:s.v") . '. Delaying for ' . $delay . ' seconds.');

        Console::loop(function () use ($logError) {
            try {
                $this->start();
            } catch (Exception $error) {
                Console::error('[Error] Time: ' . date('Y-m-d H:i:s'));
                Console::error('[Error] Message: ' . $error->getMessage());
                Console::error('[Error] File: ' . $error->getFile());
                Console::error('[Error] Line: ' . $error->getLine());

                $logError($error, 'backup', 'action');
            }
        }, $sleep, $delay);
    }

    /**
     * @throws Exception
     */
    public function start(): void
    {
        $start = microtime(true);
        $time = date('Y_m_d_H_i_s');

        self::log('--- Backup Start ' . $time . ' --- ');

        $path = self::BACKUPS_PATH . '/' . $this->database . '/' . self::VERSION;
        $local = new Local($path . '/' . $time);
        $local->setTransferChunkSize(self::UPLOAD_CHUNK_SIZE);

        $tarFile = $local->getPath($time . '.tar.gz');
        $backups = $local->getRoot() . '/files';

        $this->backup($backups);
        $this->tar($backups, $tarFile);
        $this->upload($tarFile, $local);

        if (!unlink($tarFile)) {
            Console::error('Error deleting: ' . $tarFile);
        }

        $this->cleanLocalFiles($path);
        $this->cleanCloudFiles();

        self::log('--- Backup Finish ' . (microtime(true) - $start) . ' seconds --- '   . PHP_EOL . PHP_EOL);
    }

    /**
     * @throws Exception
     */
    public function backup(string $target)
    {
        self::log('Xtrabackup start');

        $start = microtime(true);

        if (!file_exists(self::BACKUPS_PATH)) {
            throw new Exception('Mount directory does not exist');
        }

        if (!file_exists($target) && !mkdir($target, 0755, true)) {
            throw new Exception('Error creating directory: ' . $target);
        }

        $filename = basename($target);
        $logfile = $target . '/../backup.log';

        $args = [
            'xtrabackup',
            '--user=' . $this->dsn->getUser(),
            '--password=' . $this->dsn->getPassword(),
            '--host=' . $this->dsn->getHost(),
            '--port=' . $this->dsn->getPort(),
            '--backup',
            '--strict',
            '--history="' . $this->database . '|' . pathinfo($filename, PATHINFO_FILENAME) . '"', // PERCONA_SCHEMA.xtrabackup_history
            '--slave-info',
            '--safe-slave-backup',
            '--safe-slave-backup-timeout=300',
            '--check-privileges', // checks if Percona XtraBackup has all the required privileges.
            '--target-dir=' . $target,
            '--compress=' . self::COMPRESS_ALGORITHM,
            '--compress-threads=' . $this->processors,
            '--parallel=' . $this->processors,
            '2> ' . $logfile,
        ];

        $this->retry(function () use ($args, $logfile, $target) {
            shell_exec('docker exec ' . $this->xtrabackupContainerId . ' ' . implode(' ', $args));
            $stderr = shell_exec('tail -1 ' . $logfile);
            if (!str_contains($stderr, 'completed OK!')) {
                shell_exec('rm -rf ' . $target . '/*');
                throw new Exception(' Backup failed: ' . $stderr);
            }
        }, self::RETRY_BACKUP);

        if (!unlink($logfile)) {
            throw new Exception('Error deleting: ' . $logfile);
        }

        self::log('Xtrabackup Finish ' . (microtime(true) - $start) . ' seconds');
    }

    /**
     * @throws Exception
     */
    public function tar(string $directory, string $file)
    {
        self::log('Tar start');
        $start = microtime(true);

        $this->retry(function () use ($directory, $file) {
            $stdout = '';
            $stderr = '';
            $cmd = 'cd ' . $directory . ' && tar -zcf ' . $file . ' . && cd ' . getcwd();
            Console::execute($cmd, '', $stdout, $stderr);
            if (!empty($stderr)) {
                throw new Exception($stderr);
            }
        }, self::RETRY_TAR);

        if (!file_exists($file)) {
            throw new Exception('Tar file not found: ' . $file);
        }

        self::log('Tar finish ' . (microtime(true) - $start) . ' seconds');
    }

    /**
     * @throws Exception
     */
    public function upload(string $file, Device $local)
    {
        $start = microtime(true);
        self::log('Upload start');
        $filename = basename($file);

        if (!$this->s3->exists('/')) {
            throw new Exception('Can\'t read s3 root directory');
        }

        $destination = $this->s3->getRoot() . '/' . $filename;

        $this->retry(function () use ($local, $file, $destination) {
            if (!$local->transfer($file, $destination, $this->s3)) {
                throw new Exception('Error uploading to ' . $destination);
            }
        }, self::RETRY_UPLOAD);

        if (!$this->s3->exists($destination)) {
            throw new Exception('File not found on cloud: ' . $destination);
        }

        self::log('Upload finish ' . (microtime(true) - $start) . ' seconds');
    }

    public static function log(string $message): void
    {
        if (!empty($message)) {
            Console::log(date('Y-m-d H:i:s') . ' ' . $message);
        }
    }

    /**
     * @throws Exception
     */
    public function checkEnvVariables(): void
    {
        foreach (
            [
            '_APP_CONNECTIONS_BACKUPS_STORAGE',
            '_APP_CONNECTIONS_DB_REPLICAS',
            ] as $env
        ) {
            if (empty(App::getEnv($env))) {
                throw new Exception('Can\'t read ' . $env);
            }
        }
    }

    public function getDsn(string $database): ?DSN
    {
        foreach (explode(',', App::getEnv('_APP_CONNECTIONS_DB_REPLICAS', '')) as $project) {
            [$db, $dsn] = explode('=', $project);
            if ($db === $database) {
                return new DSN($dsn);
            }
        }
        return null;
    }

    /**
     * @throws Exception
     */
    public function setContainerId()
    {
        $stdout = '';
        $stderr = '';
        Console::execute('docker ps -f "name=xtrabackup" --format "{{.ID}}"', '', $stdout, $stderr);
        if (!empty($stderr)) {
            throw new Exception('Error setting container Id: ' . $stderr);
        }

        $containerId = str_replace(PHP_EOL, '', $stdout);
        if (empty($containerId)) {
            throw new Exception('Xtrabackup Container ID not found');
        }

        $this->xtrabackupContainerId = $containerId;
    }

    /**
     * @throws Exception
     */
    public function setProcessors()
    {
        $stdout = '';
        $stderr = '';
        Console::execute('docker exec ' . $this->xtrabackupContainerId . ' nproc', '', $stdout, $stderr);
        if (!empty($stderr)) {
            throw new Exception('Error setting processors: ' . $stderr);
        }

        $processors = str_replace(PHP_EOL, '', $stdout);
        $processors = empty($processors) ? 1 : intval($processors);
        $this->processors = $processors;
    }

    /**
     * @throws Exception
     */
    public function retry(callable $action, int $retries, int $sleep = 1)
    {
        try {
            return $action();
        } catch (Exception $e) {
            if ($retries > 0) {
                Console::warning('Retrying (' . $retries . ') ' . $e->getMessage());
                sleep($sleep);
                return $this->retry($action, $retries - 1, $sleep);
            } else {
                throw $e;
            }
        }
    }

    /**
     * @throws Exception
     */
    public function cleanLocalFiles(string $path)
    {
        self::log('Clean local start');

        $start = microtime(true);

        $folder = scandir($path);
        if ($folder === false) {
            throw new Exception('Scan directory error ' . $path);
        }

        foreach ($folder as $item) {
            $filename = $item . '.tar.gz';
            $fullPath = $path . '/' . $item;
            if ($this->isDelete($item, self::CLEANUP_LOCAL_FILES_SECONDS)) {
                // Check if file exist on cloud before delete
                if ($this->s3->exists($this->s3->getRoot() . '/' . $filename)) {
                    $delete = true;
                } else {
                    if ($this->isDelete($item, self::CLEANUP_CLOUD_FILES_SECONDS)) {
                        $delete = true;
                    } else {
                        Console::warning('Skipping delete not found on cloud: ' . $filename . ' ');
                        $delete = false;
                    }
                }

                if ($delete === true) {
                    Console::success('Deleting: ' . $fullPath);
                    shell_exec('rm -rf ' . $fullPath);
                }
            }
        }

        self::log('Clean local finish ' . (microtime(true) - $start) . ' seconds');
    }

    /**
     * @throws Exception
     */
    public function cleanCloudFiles(): void
    {
        self::log('Clean cloud start');
        $start = microtime(true);
        $files = $this->s3->getFiles($this->s3->getRoot());
        if ($files['KeyCount'] > 1) { // Bug when is one returned it returns an object not an array!
            foreach ($files['Contents'] as $file) {
                $date = basename(basename($file['Key']), '.tar.gz');
                if ($this->isDelete($date, self::CLEANUP_CLOUD_FILES_SECONDS)) {
                    if ($this->s3->delete($file['Key'])) {
                        Console::success('Deleting: ' . $file['Key']);
                    }
                }
            }
        }

        self::log('Clean cloud finish ' . (microtime(true) - $start) . ' seconds');
    }

    /**
     * @param string $item
     * @param int $seconds
     * @return bool
     */
    public function isDelete(string $item, int $seconds): bool
    {
        if (!str_contains($item, '_')) {
            return false;
        }

        [$year, $month, $day, $hour, $minute, $second] = explode('_', $item);
        $date = $year . '-' . $month . '-' . $day . ' ' . $hour . ':' . $minute . ':' . $second;

        try {
            $now = new \DateTime();
            $backupDate = new \DateTime($date);
            if (($now->getTimestamp() - $backupDate->getTimestamp()) > $seconds) {
                return true;
            }
        } catch (Exception $e) {
        }

        return false;
    }

    public static function getName(): string
    {
        return 'backup';
    }
}
