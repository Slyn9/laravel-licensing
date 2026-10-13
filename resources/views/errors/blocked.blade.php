@extends(config('licensing.ui.auth_layout'))

@section('title', 'Acceso suspendido')

@section('content')

<div class="text-center mb-4">
    <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-danger text-white shadow mb-3"
         style="width:64px;height:64px;">
        <i class="fas fa-lock fs-4"></i>
    </div>
    <h5 class="fw-bold text-danger">Acceso suspendido</h5>
    <p class="text-muted small mb-0">{{ \Slyn9\Licensing\Licensing::tenantName($tenant) }}</p>
</div>

@php
    // El estado real se calcula por fechas; el guardado puede ir atrasado hasta `licenses:sync`.
    $blockedStatus = $status ?? $license?->computeStatus();
@endphp

<div class="alert alert-danger border-0 rounded-3 mb-4">
    @if(! $license)
        <div class="fw-semibold mb-1">
            <i class="fas fa-triangle-exclamation me-1"></i>
            Sin licencia asignada
        </div>
        <div class="small">
            Esta sede no tiene una licencia asignada.
            Comuníquese con el administrador del sistema para regularizar su situación.
        </div>
    @else
        <div class="fw-semibold mb-1">
            <i class="fas fa-triangle-exclamation me-1"></i>
            Licencia
            @if($blockedStatus === \Slyn9\Licensing\Enums\LicenseStatus::BLOCKED)
                bloqueada manualmente
            @else
                vencida
            @endif
        </div>
        <div class="small">
            @if($blockedStatus === \Slyn9\Licensing\Enums\LicenseStatus::BLOCKED)
                El acceso al sistema ha sido bloqueado por el administrador.
                Comuníquese con soporte para regularizar su situación.
            @else
                La licencia venció el
                <strong>{{ $license->expires_at?->format('d/m/Y') }}</strong>.
                El período de gracia finalizó el
                <strong>{{ $license->grace_until?->format('d/m/Y') }}</strong>.
                Realice el pago correspondiente para restablecer el acceso.
            @endif
        </div>
    @endif
</div>

@if($license && $license->pending_amount > 0)
<div class="card border-0 bg-body-tertiary rounded-3 mb-4">
    <div class="card-body py-3 text-center">
        <div class="text-muted small">Monto pendiente</div>
        <div class="fs-4 fw-bold text-danger">{{ config('licensing.currency.symbol') }} {{ number_format($license->pending_amount, 2) }}</div>
    </div>
</div>
@endif

<div class="text-center small text-muted mb-3">
    Contáctese con el administrador del sistema para regularizar su licencia.
</div>

<a href="{{ route(config('licensing.ui.login_route', 'login')) }}" class="btn btn-outline-secondary w-100">
    <i class="fas fa-arrow-left me-1"></i>Volver al inicio de sesión
</a>

@endsection
