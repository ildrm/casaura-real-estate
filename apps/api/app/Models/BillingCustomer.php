<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BillingCustomer extends Model
{
    use HasUuids;

    protected $fillable = ['agency_id', 'provider', 'provider_customer_id', 'version'];
}
