<?php

namespace Appwrite\Platform\Tasks;

use Exception;
use League\Csv\CannotInsertRecord;
use Utopia\App;
use Utopia\Platform\Action;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Query;
use League\Csv\Writer;
use PHPMailer\PHPMailer\PHPMailer;
use Utopia\Pools\Group;
use Utopia\Registry\Registry;

class GetMigrationStats extends Action
{
    /*
     * Csv cols headers
     */
    private array $columns = [
        'Project ID',
        '$id',
        '$createdAt',
        'status',
        'stage',
        'source'
    ];

    protected string $directory = '/usr/local';
    protected string $path;
    protected string $date;

    public static function getName(): string
    {
        return 'get-migration-stats';
    }

    public function __construct()
    {

        $this
            ->desc('Get stats for projects')
            ->inject('pools')
            ->inject('cache')
            ->inject('dbForConsole')
            ->inject('register')
            ->callback(function (Group $pools, Cache $cache, Database $dbForConsole, Registry $register) {
                $this->action($pools, $cache, $dbForConsole, $register);
            });
    }

    /**
     * @throws \Utopia\Exception
     * @throws CannotInsertRecord
     */
    public function action(Group $pools, Cache $cache, Database $dbForConsole, Registry $register): void
    {
        //docker compose exec -t appwrite get-migration-stats

        Console::title('Migration stats calculation V1');
        Console::success(APP_NAME . ' Migration stats calculation has started');

        /* Initialise new Utopia app */
        $app = new App('UTC');
        $console = $app->getResource('console');

        /** CSV stuff */
        $this->date = date('Y-m-d');
        $this->path = "{$this->directory}/migration_stats_{$this->date}.csv";
        $csv = Writer::createFromPath($this->path, 'w');
        $csv->insertOne($this->columns);

        /** Database connections */
        $totalProjects = $dbForConsole->count('projects');
        Console::success("Found a total of: {$totalProjects} projects");

        $projects = [$console];
        $count = 0;
        $limit = 100;
        $sum = 100;
        $offset = 0;
        while (!empty($projects)) {
            foreach ($projects as $project) {

                /**
                 * Skip user projects with id 'console'
                 */
                if ($project->getId() === 'console') {
                    continue;
                }

                Console::info("Getting stats for {$project->getId()}");

                try {
                    $database = $project->getAttribute('database');
                    $adapter = $pools
                        ->get($database)
                        ->pop()
                        ->getResource();

                    $dbForProject = new Database($adapter, $cache);
                    $dbForProject->setDatabase('appwrite');

                    if ($database === DATABASE_SHARED_TABLES) {
                        $dbForProject
                            ->setSharedTables(true)
                            ->setTenant($project->getInternalId())
                            ->setNamespace('');
                    } else {
                        $dbForProject
                            ->setSharedTables(false)
                            ->setTenant(null)
                            ->setNamespace('_' . $project->getInternalId());
                    }

                    /** Get Project ID */
                    $stats['Project ID'] = $project->getId();

                    /** Get Migration details */
                    $migrations = $dbForProject->find('migrations', [
                        Query::limit(500)
                    ]);

                    $migrations = array_map(function ($migration) use ($project) {
                        return [
                            $project->getId(),
                            $migration->getAttribute('$id'),
                            $migration->getAttribute('$createdAt'),
                            $migration->getAttribute('status'),
                            $migration->getAttribute('stage'),
                            $migration->getAttribute('source'),
                        ];
                    }, $migrations);

                    if (!empty($migrations)) {
                        $csv->insertAll($migrations);
                    }
                } catch (\Throwable $th) {
                    Console::error('Failed on project ("' . $project->getId() . '") with error on File: ' . $th->getFile() . '  line no: ' . $th->getline() . ' with message: ' . $th->getMessage());
                } finally {
                    $pools
                        ->get($db)
                        ->reclaim();
                }
            }

            $sum = \count($projects);

            $projects = $dbForConsole->find('projects', [
                Query::limit($limit),
                Query::offset($offset),
            ]);

            $offset = $offset + $limit;
            $count = $count + $sum;
        }

        Console::log('Iterated through ' . $count - 1 . '/' . $totalProjects . ' projects...');

        $pools
            ->get('console')
            ->reclaim();

        /** @var PHPMailer $mail */
        $mail = $register->get('smtp');

        $mail->clearAddresses();
        $mail->clearAllRecipients();
        $mail->clearReplyTos();
        $mail->clearAttachments();
        $mail->clearBCCs();
        $mail->clearCCs();

        try {
            /** Addresses */
            $mail->setFrom(App::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM), 'Appwrite Cloud Hamster');
            $recipients = explode(',', App::getEnv('_APP_USERS_STATS_RECIPIENTS', ''));

            foreach ($recipients as $recipient) {
                $mail->addAddress($recipient);
            }

            /** Attachments */
            $mail->addAttachment($this->path);

            /** Content */
            $mail->Subject = "Migration Report for {$this->date}";
            $mail->Body = "Please find the migration report atttached";
            $mail->send();
            Console::success('Email has been sent!');
        } catch (Exception $e) {
            Console::error("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
        }
    }
}
