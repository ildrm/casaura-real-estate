<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasUuids;

    protected $fillable = [
        'agency_id', 'subscription_id', 'provider', 'provider_invoice_id', 'number',
        'status', 'subtotal_minor', 'tax_minor', 'total_minor', 'currency',
        'period_starts_at', 'period_ends_at', 'hosted_invoice_url', 'invoice_pdf_url',
        'provider_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'period_starts_at' => 'datetime',
            'period_ends_at' => 'datetime',
            'provider_updated_at' => 'datetime',
        ];
    }
}
