@extends(config('licensing.ui.layout'))

@section('title', 'Licencia — '.\Slyn9\Licensing\Licensing::tenantName($tenant))

@section('content')
@php
    $currency = config('licensing.currency.symbol');
    $rn = config('licensing.routes.name');
    $branchKey = \Slyn9\Licensing\Licensing::branchKey();
    $branchName = config('licensing.branches.name_attribute', 'name');
    $hasBranches = \Slyn9\Licensing\Licensing::branchesEnabled();
    // En modo managed las acciones comerciales se ocultan (el servicio las rechaza igualmente).
    $manages = \Slyn9\Licensing\Licensing::allowsLocalManagement();
@endphp
<div class="container-fluid">

    {{-- Breadcrumb --}}
    <nav class="mb-4">
        <a href="{{ route($rn.'licenses.index') }}" class="text-decoration-none text-muted">
            <i class="fas fa-id-card me-1"></i>Licencias
        </a>
        <span class="text-muted mx-1">/</span>
        <span class="fw-semibold">{{ \Slyn9\Licensing\Licensing::tenantName($tenant) }}</span>
    </nav>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible d-flex align-items-center gap-3 rounded-3 mb-4" role="alert">
            <i class="fas fa-circle-check fs-4 text-success flex-shrink-0" aria-hidden="true"></i>
            <div class="flex-grow-1">{{ session('success') }}</div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Cerrar"></button>
        </div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger alert-dismissible d-flex align-items-start gap-3 rounded-3 mb-4" role="alert">
            <i class="fas fa-triangle-exclamation fs-4 text-danger flex-shrink-0" aria-hidden="true"></i>
            <ul class="mb-0 flex-grow-1 ps-3">
                @foreach($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Cerrar"></button>
        </div>
    @endif

    <div class="row g-4">

        {{-- ── PANEL IZQUIERDO: Nueva licencia ── --}}
        @if($manages)
        <div class="col-12 col-lg-4">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header border-0 pt-4 pb-0 px-4">
                    <div class="d-flex align-items-center gap-3">
                        <div class="bg-success-subtle text-success rounded-3 d-flex align-items-center justify-content-center flex-shrink-0"
                             style="width:40px;height:40px;">
                            <i class="fas fa-plus-circle" aria-hidden="true"></i>
                        </div>
                        <div>
                            <h6 class="mb-0 fw-bold fs-6">Asignar nueva licencia</h6>
                            <small class="text-muted">Completa todos los campos requeridos</small>
                        </div>
                    </div>
                </div>
                <div class="card-body p-4">
                    <form method="POST" action="{{ route($rn.'licenses.store', $tenant) }}">
                        @csrf

                        <div class="row g-3">

                            <div class="col-12">
                                <label class="form-label fw-semibold mb-2" for="licTypeSelect">
                                    Tipo de licencia <span class="text-danger">*</span>
                                </label>
                                <select name="license_type_id" class="form-select" id="licTypeSelect" required>
                                    <option value="">Seleccione...</option>
                                    @foreach($licenseTypes as $lt)
                                        <option value="{{ $lt->id }}"
                                                data-price="{{ $lt->base_price }}"
                                                data-permanent="{{ $lt->isPermanent() ? '1' : '0' }}">
                                            {{ $lt->name }} — {{ $currency }} {{ number_format($lt->base_price, 2) }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            @if($hasBranches)
                            <div class="col-12">
                                <label class="form-label fw-semibold mb-2" for="{{ $branchKey }}">Sede</label>
                                <select name="{{ $branchKey }}" class="form-select" id="{{ $branchKey }}">
                                    <option value="">Toda la empresa (licencia general)</option>
                                    @foreach($branches as $b)
                                        <option value="{{ $b->getKey() }}" @selected(old($branchKey) == $b->getKey())>{{ $b->{$branchName} }}</option>
                                    @endforeach
                                </select>
                                <div class="text-muted mt-1">Una licencia de sede manda sobre la general.</div>
                                @error($branchKey) <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>
                            @endif

                            <div class="col-12">
                                <label class="form-label fw-semibold mb-2" for="starts_at">
                                    Fecha de inicio <span class="text-danger">*</span>
                                </label>
                                <input type="date" name="starts_at" class="form-control" id="starts_at"
                                       value="{{ now()->format('Y-m-d') }}" required>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold mb-2" for="totalAmount">
                                    Monto total ({{ $currency }}) <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text">{{ $currency }}</span>
                                    <input type="number" name="total_amount" id="totalAmount" class="form-control"
                                           step="0.01" min="0" value="0" required>
                                </div>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold mb-2" for="installments_count">
                                    Número de cuotas <span class="text-danger">*</span>
                                </label>
                                <input type="number" name="installments_count" class="form-control"
                                       id="installments_count" min="1" max="36" value="1" required>
                                <div class="text-muted mt-1">1 = pago único. Cuotas mensuales desde la fecha de inicio.</div>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold mb-2" for="notes">Notas</label>
                                <textarea name="notes" class="form-control" id="notes" rows="2" maxlength="500"></textarea>
                            </div>

                            <div class="col-12">
                                <button type="submit" class="btn btn-primary d-flex align-items-center gap-2 px-4 fw-semibold w-100">
                                    <i class="fas fa-check" aria-hidden="true"></i>
                                    Asignar licencia
                                </button>
                            </div>

                        </div>
                    </form>
                </div>
            </div>
        </div>
        @endif

        {{-- ── PANEL DERECHO: Historial de licencias ── --}}
        <div class="col-12 {{ $manages ? 'col-lg-8' : '' }}">

            @forelse($licenses as $license)

                @php
                    // Estado real por fechas (el guardado se actualiza con `licenses:sync`).
                    $effective   = $license->effective_status;
                    $statusColor = $effective->color();
                    $statusLabel = $effective->label();
                    $isBlocked   = $license->status === \Slyn9\Licensing\Enums\LicenseStatus::BLOCKED;
                    $isCancelled = $license->status === \Slyn9\Licensing\Enums\LicenseStatus::CANCELLED;
                    $isExpired   = $isBlocked && $license->statusByDates(null, $license->isTrialType()) === \Slyn9\Licensing\Enums\LicenseStatus::EXPIRED;
                    $canRenew    = ! $isCancelled && $license->licenseType && ! $license->licenseType->isPermanent();
                @endphp

                <div class="card border-0 shadow-sm rounded-4 mb-4">

                    {{-- Cabecera de la licencia --}}
                    <div class="card-header border-0 pt-4 pb-3 px-4">
                        <div class="d-flex flex-wrap align-items-center gap-3">
                            <div class="d-flex align-items-center gap-3">
                                <div class="bg-{{ $statusColor }}-subtle text-{{ $statusColor }} rounded-3 d-flex align-items-center justify-content-center flex-shrink-0"
                                     style="width:40px;height:40px;">
                                    <i class="fas fa-id-card" aria-hidden="true"></i>
                                </div>
                                <div>
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <span class="fw-bold">{{ $license->licenseType->name }}</span>
                                        <span class="badge rounded-pill px-3 py-2 bg-{{ $statusColor }}-subtle text-{{ $statusColor }} border border-{{ $statusColor }}">
                                            {{ $statusLabel }}
                                        </span>
                                    </div>
                                    <small class="text-muted">
                                        {{ $hasBranches && $license->branch ? $license->branch->{$branchName} : 'Toda la empresa' }} ·
                                        {{ $license->starts_at->format('d/m/Y') }}
                                        @if($license->expires_at)
                                            → {{ $license->expires_at->format('d/m/Y') }}
                                        @else
                                            → <span class="text-success fw-semibold">Permanente</span>
                                        @endif
                                    </small>
                                </div>
                            </div>

                            {{-- Acciones de estado: cada una abre su formulario (el motivo es obligatorio) --}}
                            @if($manages && ! $isCancelled)
                            <div class="ms-auto d-flex flex-wrap gap-2">
                                @if($canRenew)
                                    <button type="button" class="btn btn-outline-primary d-flex align-items-center gap-2 px-3"
                                            data-bs-toggle="collapse" data-bs-target="#renew-{{ $license->id }}"
                                            aria-label="Renovar licencia">
                                        <i class="fas fa-rotate" aria-hidden="true"></i>
                                        <span class="d-none d-sm-inline">Renovar</span>
                                    </button>
                                @endif
                                @if($isBlocked)
                                    <button type="button" class="btn btn-success d-flex align-items-center gap-2 px-3"
                                            data-bs-toggle="collapse" data-bs-target="#unblock-{{ $license->id }}"
                                            aria-label="Reactivar licencia">
                                        <i class="fas fa-lock-open" aria-hidden="true"></i>
                                        <span class="d-none d-sm-inline">Reactivar</span>
                                    </button>
                                @else
                                    <button type="button" class="btn btn-outline-warning d-flex align-items-center gap-2 px-3"
                                            data-bs-toggle="collapse" data-bs-target="#block-{{ $license->id }}"
                                            aria-label="Bloquear licencia">
                                        <i class="fas fa-lock" aria-hidden="true"></i>
                                        <span class="d-none d-sm-inline">Bloquear</span>
                                    </button>
                                @endif
                                <button type="button" class="btn btn-outline-danger d-flex align-items-center gap-2 px-3"
                                        data-bs-toggle="collapse" data-bs-target="#cancel-{{ $license->id }}"
                                        aria-label="Cancelar licencia">
                                    <i class="fas fa-ban" aria-hidden="true"></i>
                                    <span class="d-none d-sm-inline">Cancelar</span>
                                </button>
                            </div>
                            @endif
                        </div>

                        @if($manages && ! $isCancelled)
                        <div class="accordion-flush mt-3">

                            @if($canRenew)
                            <div class="collapse" id="renew-{{ $license->id }}">
                                <form method="POST" action="{{ route($rn.'licenses.renew', $license) }}"
                                      class="border rounded-3 p-3 bg-body-tertiary">
                                    @csrf
                                    <div class="fw-semibold mb-1">Renovar licencia</div>
                                    <div class="text-muted small mb-3">
                                        La nueva vigencia empieza en la fecha de vencimiento actual (o ahora, si ya venció)
                                        y se generan las cuotas del nuevo período.
                                    </div>
                                    <div class="row g-3">
                                        <div class="col-12 col-sm-6">
                                            <label class="form-label fw-semibold">Monto de la renovación ({{ $currency }})</label>
                                            <input type="number" name="total_amount" class="form-control" step="0.01" min="0"
                                                   value="{{ $license->licenseType->base_price }}">
                                        </div>
                                        <div class="col-12 col-sm-3">
                                            <label class="form-label fw-semibold">Cuotas</label>
                                            <input type="number" name="installments_count" class="form-control" min="1" max="36" value="1">
                                        </div>
                                        <div class="col-12 col-sm-3 d-flex align-items-end">
                                            <button class="btn btn-primary w-100 fw-semibold">Renovar</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                            @endif

                            @if($isBlocked)
                            <div class="collapse" id="unblock-{{ $license->id }}">
                                <form method="POST" action="{{ route($rn.'licenses.unblock', $license) }}"
                                      class="border rounded-3 p-3 bg-body-tertiary">
                                    @csrf
                                    <div class="fw-semibold mb-1">Reactivar licencia</div>
                                    @if($isExpired)
                                        <div class="alert alert-warning py-2 small">
                                            Las fechas de esta licencia ya vencieron. Indique una nueva fecha de
                                            vencimiento o márquela para renovar; de lo contrario no se puede reactivar.
                                        </div>
                                    @endif
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <label class="form-label fw-semibold">Motivo <span class="text-danger">*</span></label>
                                            <textarea name="reason" class="form-control" rows="2" maxlength="500" required></textarea>
                                        </div>
                                        <div class="col-12 col-sm-4">
                                            <label class="form-label fw-semibold">Nueva fecha de vencimiento{!! $isExpired ? ' <span class="text-danger">*</span>' : '' !!}</label>
                                            <input type="date" name="expires_at" class="form-control"
                                                   min="{{ now()->addDay()->format('Y-m-d') }}">
                                        </div>
                                        <div class="col-12 col-sm-5">
                                            <div class="form-check mt-4">
                                                <input class="form-check-input" type="checkbox" name="renew" value="1" id="renew-chk-{{ $license->id }}">
                                                <label class="form-check-label" for="renew-chk-{{ $license->id }}">
                                                    Renovar en lugar de fijar una fecha
                                                </label>
                                            </div>
                                        </div>
                                        <div class="col-12 col-sm-3 d-flex align-items-end">
                                            <button class="btn btn-success w-100 fw-semibold">Reactivar</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                            @else
                            <div class="collapse" id="block-{{ $license->id }}">
                                <form method="POST" action="{{ route($rn.'licenses.block', $license) }}"
                                      class="border rounded-3 p-3 bg-body-tertiary">
                                    @csrf
                                    <div class="fw-semibold mb-2">Bloquear licencia</div>
                                    <div class="row g-3">
                                        <div class="col-12 col-sm-9">
                                            <label class="form-label fw-semibold">Motivo <span class="text-danger">*</span></label>
                                            <textarea name="reason" class="form-control" rows="2" maxlength="500" required></textarea>
                                        </div>
                                        <div class="col-12 col-sm-3 d-flex align-items-end">
                                            <button class="btn btn-warning w-100 fw-semibold">Bloquear</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                            @endif

                            <div class="collapse" id="cancel-{{ $license->id }}">
                                <form method="POST" action="{{ route($rn.'licenses.cancel', $license) }}"
                                      class="border rounded-3 p-3 bg-body-tertiary">
                                    @csrf
                                    <div class="fw-semibold mb-2">Cancelar licencia</div>
                                    <div class="row g-3">
                                        <div class="col-12 col-sm-9">
                                            <label class="form-label fw-semibold">Motivo <span class="text-danger">*</span></label>
                                            <textarea name="reason" class="form-control" rows="2" maxlength="500" required></textarea>
                                        </div>
                                        <div class="col-12 col-sm-3 d-flex align-items-end">
                                            <button class="btn btn-danger w-100 fw-semibold">Cancelar licencia</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                        @endif
                    </div>

                    <div class="card-body p-4">

                        {{-- Resumen financiero --}}
                        <div class="row g-3 mb-4">
                            <div class="col-4">
                                <div class="bg-body-tertiary rounded-3 p-3 text-center">
                                    <div class="fw-semibold text-muted" style="font-size:.78rem;letter-spacing:.04em;">TOTAL</div>
                                    <div class="fs-5 fw-bold mt-1">{{ $currency }} {{ number_format($license->total_amount, 2) }}</div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="bg-success-subtle rounded-3 p-3 text-center">
                                    <div class="fw-semibold text-muted" style="font-size:.78rem;letter-spacing:.04em;">PAGADO</div>
                                    <div class="fs-5 fw-bold text-success mt-1">{{ $currency }} {{ number_format($license->paid_amount, 2) }}</div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="bg-{{ $license->pending_amount > 0 ? 'danger' : 'success' }}-subtle rounded-3 p-3 text-center">
                                    <div class="fw-semibold text-muted" style="font-size:.78rem;letter-spacing:.04em;">PENDIENTE</div>
                                    <div class="fs-5 fw-bold text-{{ $license->pending_amount > 0 ? 'danger' : 'success' }} mt-1">
                                        {{ $currency }} {{ number_format($license->pending_amount, 2) }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Cuotas --}}
                        @if($license->installments->isNotEmpty())
                            <h6 class="fw-semibold text-muted mb-3"
                                style="font-size:.8rem;letter-spacing:.04em;">CUOTAS</h6>
                            <div class="table-responsive mb-4">
                                <table class="table align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th class="py-3 text-muted fw-semibold">#</th>
                                            <th class="py-3 text-muted fw-semibold">Vencimiento</th>
                                            <th class="py-3 text-muted fw-semibold text-end">Monto</th>
                                            <th class="py-3 text-muted fw-semibold text-end">Pagado</th>
                                            <th class="py-3 text-muted fw-semibold text-center">Estado</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($license->installments as $inst)
                                            <tr class="{{ $inst->isOverdue() ? 'table-danger' : '' }}">
                                                <td class="py-3">{{ $inst->installment_number }}</td>
                                                <td class="py-3">{{ $inst->due_date->format('d/m/Y') }}</td>
                                                <td class="py-3 text-end fw-semibold">{{ $currency }} {{ number_format($inst->amount_due, 2) }}</td>
                                                <td class="py-3 text-end {{ (float) $inst->paid_amount > 0 ? 'text-success' : 'text-muted' }}">
                                                    {{ $currency }} {{ number_format($inst->paid_amount, 2) }}
                                                </td>
                                                <td class="py-3 text-center">
                                                    @if($inst->status === 'paid')
                                                        <span class="badge rounded-pill px-3 py-2 bg-success-subtle text-success border border-success">
                                                            <i class="fas fa-check me-1" aria-hidden="true"></i>Pagado
                                                        </span>
                                                    @elseif($inst->isOverdue())
                                                        <span class="badge rounded-pill px-3 py-2 bg-danger-subtle text-danger border border-danger">
                                                            <i class="fas fa-clock me-1" aria-hidden="true"></i>Vencida
                                                        </span>
                                                    @else
                                                        <span class="badge rounded-pill px-3 py-2 bg-secondary-subtle text-secondary border border-secondary">
                                                            <i class="fas fa-hourglass-half me-1" aria-hidden="true"></i>Pendiente
                                                        </span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                        {{-- Registrar pago --}}
                        @if($manages && ! $isCancelled && $license->pending_amount > 0)
                            <h6 class="fw-semibold text-muted mb-3"
                                style="font-size:.8rem;letter-spacing:.04em;">REGISTRAR PAGO</h6>
                            <form method="POST" action="{{ route($rn.'licenses.payment', $license) }}">
                                @csrf
                                <div class="row g-3">
                                    <div class="col-12 col-sm-6 col-lg-3">
                                        <label class="form-label fw-semibold mb-2">Monto ({{ $currency }}) <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text">{{ $currency }}</span>
                                            <input type="number" name="amount" class="form-control"
                                                   placeholder="0.00" step="0.01" min="0.01"
                                                   max="{{ $license->pending_amount }}" required>
                                        </div>
                                    </div>
                                    <div class="col-12 col-sm-6 col-lg-3">
                                        <label class="form-label fw-semibold mb-2">Fecha <span class="text-danger">*</span></label>
                                        <input type="date" name="paid_at" class="form-control"
                                               value="{{ now()->format('Y-m-d') }}" required>
                                    </div>
                                    <div class="col-12 col-sm-6 col-lg-3">
                                        <label class="form-label fw-semibold mb-2">Método <span class="text-danger">*</span></label>
                                        <select name="payment_method" class="form-select" required>
                                            @foreach(config('licensing.payment_methods') as $key => $label)
                                                <option value="{{ $key }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-12 col-sm-6 col-lg-3">
                                        <label class="form-label fw-semibold mb-2">Cuota</label>
                                        <select name="license_installment_id" class="form-select">
                                            <option value="">Pago libre</option>
                                            @foreach($license->installments->where('status', '!=', 'paid') as $inst)
                                                <option value="{{ $inst->id }}">
                                                    Cuota {{ $inst->installment_number }} (saldo {{ $currency }} {{ number_format($inst->remaining_amount, 2) }})
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-12 col-sm-6">
                                        <label class="form-label fw-semibold mb-2">Referencia / N° operación</label>
                                        <input type="text" name="reference" class="form-control"
                                               placeholder="Referencia">
                                    </div>
                                    <div class="col-12 col-sm-4">
                                        <label class="form-label fw-semibold mb-2">Notas</label>
                                        <input type="text" name="notes" class="form-control"
                                               placeholder="Notas opcionales">
                                    </div>
                                    <div class="col-12 col-sm-2 d-flex align-items-end">
                                        <button type="submit" class="btn btn-primary d-flex align-items-center gap-2 px-4 fw-semibold w-100">
                                            <i class="fas fa-check" aria-hidden="true"></i>Guardar
                                        </button>
                                    </div>
                                </div>
                            </form>
                        @endif

                        {{-- Historial de pagos --}}
                        @if($license->payments->isNotEmpty())
                            <h6 class="fw-semibold text-muted mt-4 mb-3"
                                style="font-size:.8rem;letter-spacing:.04em;">PAGOS REGISTRADOS</h6>
                            <div class="table-responsive">
                                <table class="table align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th class="py-3 text-muted fw-semibold">Fecha</th>
                                            <th class="py-3 text-muted fw-semibold">Método</th>
                                            <th class="py-3 text-muted fw-semibold">Referencia</th>
                                            <th class="py-3 text-muted fw-semibold text-end">Monto</th>
                                            <th class="py-3 text-muted fw-semibold">Registrado por</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($license->payments as $pay)
                                            <tr>
                                                <td class="py-3">{{ $pay->paid_at->format('d/m/Y') }}</td>
                                                <td class="py-3 text-capitalize">{{ $pay->payment_method }}</td>
                                                <td class="py-3 text-muted" style="font-size:.82rem;">{{ $pay->reference ?? '—' }}</td>
                                                <td class="py-3 text-end text-success fw-bold">
                                                    {{ $currency }} {{ number_format($pay->amount, 2) }}
                                                </td>
                                                <td class="py-3">{{ $pay->recorder?->name ?? '—' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                    </div>
                </div>

            @empty
                <div class="card border-0 shadow-sm rounded-4">
                    <div class="card-body text-center py-5 text-muted">
                        <i class="fas fa-id-card fa-3x mb-3 opacity-25 d-block" aria-hidden="true"></i>
                        <p class="fs-5 fw-semibold mb-1">Sin licencias</p>
                        <p class="mb-0">Esta empresa no tiene licencias asignadas aún.</p>
                    </div>
                </div>
            @endforelse

        </div>
    </div>
</div>

@push('scripts')
<script>
document.getElementById('licTypeSelect')?.addEventListener('change', function () {
    const opt = this.options[this.selectedIndex];
    const price = opt.dataset.price ?? 0;
    document.getElementById('totalAmount').value = parseFloat(price).toFixed(2);
});
</script>
@endpush
@endsection
