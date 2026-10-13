@extends(config('licensing.ui.layout'))

@section('title', 'Licencias')

@section('content')
@php $rn = config('licensing.routes.name'); @endphp
<div class="container-fluid">

    <div class="card border-0 shadow-sm rounded-4">

        <div class="card-header border-0 rounded-top-4 d-flex flex-wrap align-items-center gap-3 py-3 px-4">
            <div class="d-flex align-items-center gap-3">
                <div class="bg-primary-subtle text-primary rounded-3 d-flex align-items-center justify-content-center flex-shrink-0"
                     style="width:46px;height:46px;">
                    <i class="fas fa-id-card" aria-hidden="true"></i>
                </div>
                <div>
                    <h5 class="mb-0 fw-bold">Gestión de Licencias</h5>
                    <small class="text-muted">Licencias activas por empresa</small>
                </div>
            </div>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="py-3 text-muted fw-semibold px-4">Empresa</th>
                            <th class="py-3 text-muted fw-semibold">Tipo de licencia</th>
                            <th class="py-3 text-muted fw-semibold">Estado</th>
                            <th class="py-3 text-muted fw-semibold">Inicio</th>
                            <th class="py-3 text-muted fw-semibold">Vencimiento</th>
                            <th class="py-3 text-muted fw-semibold text-end">Total</th>
                            <th class="py-3 text-muted fw-semibold text-end">Pagado</th>
                            <th class="py-3 text-muted fw-semibold text-end">Pendiente</th>
                            <th class="py-3 text-muted fw-semibold text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($tenants as $tenant)
                            @php $lic = $latest[$tenant->getKey()] ?? null; @endphp
                            <tr>
                                <td class="fw-semibold">{{ \Slyn9\Licensing\Licensing::tenantName($tenant) }}</td>
                                <td>
                                    @if($lic)
                                        {{ $lic->licenseType->name }}
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if($lic)
                                        <span class="badge bg-{{ $lic->effective_status->color() }}">
                                            {{ $lic->effective_status->label() }}
                                        </span>
                                    @else
                                        <span class="badge bg-secondary">Sin licencia</span>
                                    @endif
                                </td>
                                <td>{{ $lic?->starts_at?->format('d/m/Y') ?? '—' }}</td>
                                <td>
                                    @if($lic?->expires_at)
                                        {{ $lic->expires_at->format('d/m/Y') }}
                                        @if($lic->days_until_expiry !== null && $lic->days_until_expiry <= 30 && $lic->days_until_expiry >= 0)
                                            <span class="badge bg-warning text-dark ms-1">{{ $lic->days_until_expiry }}d</span>
                                        @endif
                                    @else
                                        <span class="text-muted">Permanente</span>
                                    @endif
                                </td>
                                <td class="text-end">{{ $lic ? config('licensing.currency.symbol').' '.number_format($lic->total_amount,2) : '—' }}</td>
                                <td class="text-end text-success">{{ $lic ? config('licensing.currency.symbol').' '.number_format($lic->paid_amount,2) : '—' }}</td>
                                <td class="text-end {{ ($lic?->pending_amount ?? 0) > 0 ? 'text-danger fw-semibold' : '' }}">
                                    {{ $lic ? config('licensing.currency.symbol').' '.number_format($lic->pending_amount,2) : '—' }}
                                </td>
                                <td class="text-center">
                                    <a href="{{ route($rn.'licenses.show', $tenant) }}"
                                       class="btn btn-outline-primary d-flex align-items-center gap-2 mx-auto"
                                       style="width:fit-content;"
                                       aria-label="Ver licencias de {{ \Slyn9\Licensing\Licensing::tenantName($tenant) }}">
                                        <i class="fas fa-eye" aria-hidden="true"></i>
                                        <span class="d-none d-sm-inline">Ver</span>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">
                                    No hay empresas registradas.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>
@endsection
