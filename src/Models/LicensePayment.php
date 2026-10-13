<?php

namespace Slyn9\Licensing\Models;

use Illuminate\Database\Eloquent\Model;
use Slyn9\Licensing\Licensing;
use Slyn9\Licensing\Models\Concerns\HasLicenseUuid;

class LicensePayment extends Model
{
    use HasLicenseUuid;

    protected $fillable = [
        'company_license_id', 'license_installment_id',
        'amount', 'paid_at', 'payment_method', 'reference', 'notes', 'recorded_by',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
        'amount' => 'decimal:2',
    ];

    public function license()
    {
        return $this->belongsTo(CompanyLicense::class, 'company_license_id');
    }

    public function installment()
    {
        return $this->belongsTo(LicenseInstallment::class, 'license_installment_id');
    }

    public function recorder()
    {
        return $this->belongsTo(Licensing::userModel(), 'recorded_by');
    }
}
