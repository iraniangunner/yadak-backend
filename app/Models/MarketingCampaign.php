<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingCampaign extends Model
{
    use HasFactory;
    protected $fillable = [
        'sent_by',
        'filters',
        'message',
        'recipient_count',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'recipient_count' => 'integer',
        ];
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
