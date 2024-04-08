<?php

namespace Appwrite\Platform;

use Appwrite\Platform\Services\Tasks;
use Appwrite\Platform\Services\Workers;
use Appwrite\Platform\Modules\Core;
use Utopia\Platform\Platform;

class Appwrite extends Platform
{
    public function __construct()
    {
        parent::__construct(new Core());
        $this->addService('tasks', new Tasks());
        $this->addService('workers', new Workers());
    }
}
