<?php

namespace Slyn9\Licensing\Tests\Suites;

use Slyn9\Licensing\Tests\Behavior\AssignmentBehavior;
use Slyn9\Licensing\Tests\Behavior\AuditTrailBehavior;
use Slyn9\Licensing\Tests\Behavior\MissingLicenseBehavior;
use Slyn9\Licensing\Tests\Behavior\PaymentBehavior;
use Slyn9\Licensing\Tests\Behavior\RepairBehavior;
use Slyn9\Licensing\Tests\Behavior\ResolutionBehavior;
use Slyn9\Licensing\Tests\Behavior\SmokeBehavior;
use Slyn9\Licensing\Tests\Behavior\StatusSyncBehavior;
use Slyn9\Licensing\Tests\Behavior\UnblockRenewBehavior;
use Slyn9\Licensing\Tests\Topologies\T1TestCase;

class T1Test extends T1TestCase
{
    use AssignmentBehavior;
    use AuditTrailBehavior;
    use MissingLicenseBehavior;
    use PaymentBehavior;
    use RepairBehavior;
    use ResolutionBehavior;
    use SmokeBehavior;
    use StatusSyncBehavior;
    use UnblockRenewBehavior;
}
