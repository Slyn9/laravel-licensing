<?php

namespace Slyn9\Licensing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Slyn9\Licensing\Models\Concerns\HasLicenseUuid;

class LicenseInstallment extends Model
{
    use HasLicenseUuid;
    use SoftDeletes;

    protected $fillable = [
        'company_license_id', 'installment_number',
        'amount_due', 'paid_amount', 'due_date', 'paid_at', 'status',
    ];

    protected $casts = [
        'due_date' => 'date',
        'paid_at' => 'datetime',
        'amount_due' => 'decimal:2',
        'paid_amount' => 'decimal:2',
    ];

    public function license()
    {
        return $this->belongsTo(CompanyLicense::class, 'company_license_id');
    }

    public function payments()
    {
        return $this->hasMany(LicensePayment::class);
    }

    public function getRemainingAmountAttribute(): float
    {
        return max(0, (float) $this->amount_due - (float) $this->paid_amount);
    }

    /** Vencida y sin cubrir por completo (no depende de que `licenses:sync` ya la haya marcado). */
    public function isOverdue(): bool
    {
        return $this->status !== 'paid' && $this->due_date->lt(today());
    }
}
