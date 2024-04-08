<?php

namespace Appwrite\Platform\Services;

use Utopia\Platform\Service;
use Appwrite\Platform\Tasks\Doctor;
use Appwrite\Platform\Tasks\Install;
use Appwrite\Platform\Tasks\Maintenance;
use Appwrite\Platform\Tasks\Migrate;
use Appwrite\Platform\Tasks\Schedule;
use Appwrite\Platform\Tasks\SDKs;
use Appwrite\Platform\Tasks\Specs;
use Appwrite\Platform\Tasks\SSL;
use Appwrite\Platform\Tasks\Vars;
use Appwrite\Platform\Tasks\Version;
use Appwrite\Platform\Tasks\Upgrade;

class Tasks extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_TASK;
        $this
            ->addAction(Version::getName(), new Version())
            ->addAction(Vars::getName(), new Vars())
            ->addAction(SSL::getName(), new SSL())
            ->addAction(Doctor::getName(), new Doctor())
            ->addAction(Install::getName(), new Install())
            ->addAction(Upgrade::getName(), new Upgrade())
            ->addAction(Maintenance::getName(), new Maintenance())
            ->addAction(Schedule::getName(), new Schedule())
            ->addAction(Migrate::getName(), new Migrate())
            ->addAction(SDKs::getName(), new SDKs())
            ->addAction(Specs::getName(), new Specs())
        ;
    }
}
