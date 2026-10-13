<?php

namespace Slyn9\Licensing\Tests\Fixtures\Support;

use Closure;
use Illuminate\Http\Request;
use Slyn9\Licensing\Tests\Fixtures\Models\T3Tenant;

class SetFixtureContext
{
    public function __construct(private readonly FixtureContext $context) {}

    public function handle(Request $request, Closure $next)
    {
        $this->context->reset();

        $route = $request->route();

        if ($route && $route->parameter('tenant') !== null) {
            $this->context->tenantId = $route->parameter('tenant');
            $this->context->branchId = $route->parameter('branch');
        }

        if ($header = $request->header('X-Tenant')) {
            $this->context->tenantId = $header;
            $this->context->tenantModel = T3Tenant::find($header);
        }

        return $next($request);
    }
}
