{{--
    Banner de licencia (período de gracia / prueba).
    Uso en el anfitrión:  @include('licensing::partials.banner')
    $licenseWarning lo comparte el provider del módulo para las vistas de config('licensing.ui.banner_views').
--}}
@if(isset($licenseWarning))
@php
    $lwDays   = $licenseWarning->days_until_expiry;
    $lwGrace  = $licenseWarning->effective_status === \Slyn9\Licensing\Enums\LicenseStatus::GRACE;
    $lwColor  = $lwGrace ? 'danger' : ($lwDays !== null && $lwDays <= 7 ? 'warning' : 'info');
    $lwIcon   = $lwGrace ? 'fa-lock' : 'fa-triangle-exclamation';
@endphp
<div class="alert alert-{{ $lwColor }} alert-dismissible fade show rounded-3 mx-3 mt-3 mb-0 d-flex align-items-center gap-3">
    <i class="fas {{ $lwIcon }} fa-lg flex-shrink-0"></i>
    <div>
        @if($lwGrace)
            <strong>Período de gracia activo.</strong>
            Su licencia venció el {{ $licenseWarning->expires_at?->format('d/m/Y') }}.
            Tiene hasta el <strong>{{ $licenseWarning->grace_until?->format('d/m/Y') }}</strong> para realizar el pago.
            Después de esa fecha el sistema se bloqueará automáticamente.
        @elseif($licenseWarning->effective_status === \Slyn9\Licensing\Enums\LicenseStatus::TRIAL)
            <strong>Período de prueba.</strong>
            Su licencia trial vence el <strong>{{ $licenseWarning->expires_at?->format('d/m/Y') }}</strong>
            @if($lwDays !== null)
                (en {{ $lwDays }} día{{ $lwDays !== 1 ? 's' : '' }}).
            @endif
            Contáctese con el administrador para adquirir una licencia.
        @else
            <strong>Su licencia vence pronto.</strong>
            Fecha de vencimiento: <strong>{{ $licenseWarning->expires_at?->format('d/m/Y') }}</strong>
            @if($lwDays !== null)
                (en {{ $lwDays }} día{{ $lwDays !== 1 ? 's' : '' }}).
            @endif
        @endif
    </div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
@endif
