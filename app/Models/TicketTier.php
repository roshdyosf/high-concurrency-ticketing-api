<?php

namespace App\Models;

use App\Enums\TierType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketTier extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'price',
        'total_capacity',
    ];

    protected function casts(): array
    {
        return [
            'type' => TierType::class,
            'price' => 'decimal:2',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
