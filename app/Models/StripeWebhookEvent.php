<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StripeWebhookEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }
}
