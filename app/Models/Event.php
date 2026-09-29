<?php

namespace App\Models;

use App\Enums\EventStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Event extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'venue_name',
        'location',
        'event_date',
        'image_url',
        'image_public_id',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'datetime',
            'status' => EventStatus::class,
        ];
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }
}
