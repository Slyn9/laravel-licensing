<?php

namespace Slyn9\Licensing\Enums;

enum LicenseStatus: string
{
    case TRIAL = 'trial';
    case ACTIVE = 'active';
    case GRACE = 'grace';
    case EXPIRED = 'expired';
    case BLOCKED = 'blocked';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::TRIAL => 'Trial',
            self::ACTIVE => 'Activa',
            self::GRACE => 'Período de gracia',
            self::EXPIRED => 'Vencida',
            self::BLOCKED => 'Bloqueada',
            self::CANCELLED => 'Cancelada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::TRIAL => 'info',
            self::ACTIVE => 'success',
            self::GRACE => 'warning',
            self::EXPIRED => 'danger',
            self::BLOCKED => 'dark',
            self::CANCELLED => 'secondary',
        };
    }

    public function isAccessAllowed(): bool
    {
        return in_array($this, [self::TRIAL, self::ACTIVE, self::GRACE]);
    }

    public function isBlocking(): bool
    {
        return in_array($this, [self::EXPIRED, self::BLOCKED]);
    }
}
