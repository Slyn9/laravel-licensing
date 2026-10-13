<?php

namespace Slyn9\Licensing\Tests\Suites;

use Slyn9\Licensing\Tests\Behavior\AssignmentBehavior;
use Slyn9\Licensing\Tests\Behavior\AuditTrailBehavior;
use Slyn9\Licensing\Tests\Behavior\ContractsBehavior;
use Slyn9\Licensing\Tests\Behavior\EventsBehavior;
use Slyn9\Licensing\Tests\Behavior\ManagedModeBehavior;
use Slyn9\Licensing\Tests\Behavior\MissingLicenseBehavior;
use Slyn9\Licensing\Tests\Behavior\MultiTenantAccessBehavior;
use Slyn9\Licensing\Tests\Behavior\NoBranchesBehavior;
use Slyn9\Licensing\Tests\Behavior\PaymentBehavior;
use Slyn9\Licensing\Tests\Behavior\RepairBehavior;
use Slyn9\Licensing\Tests\Behavior\ResolutionBehavior;
use Slyn9\Licensing\Tests\Behavior\SmokeBehavior;
use Slyn9\Licensing\Tests\Behavior\SnapshotBehavior;
use Slyn9\Licensing\Tests\Behavior\StatusSyncBehavior;
use Slyn9\Licensing\Tests\Behavior\UnblockRenewBehavior;
use Slyn9\Licensing\Tests\Topologies\T3TestCase;

class T3Test extends T3TestCase
{
    use AssignmentBehavior;
    use AuditTrailBehavior;
    use ContractsBehavior;
    use EventsBehavior;
    use ManagedModeBehavior;
    use MissingLicenseBehavior;
    use PaymentBehavior;
    use RepairBehavior;
    use ResolutionBehavior;
    use SmokeBehavior;
    use SnapshotBehavior;
    use StatusSyncBehavior;
    use UnblockRenewBehavior;
    use MultiTenantAccessBehavior;
    use NoBranchesBehavior;
}
