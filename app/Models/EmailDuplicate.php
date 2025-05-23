<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailDuplicate extends Model
{
    protected $fillable = [
        'email_id',
        'duplicate_email_seq_id'
    ];

    /**
     * Get the email that this duplicate belongs to
     */
    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }
} 