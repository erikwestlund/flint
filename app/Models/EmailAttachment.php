<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailAttachment extends Model
{
    protected $fillable = [
        'email_id',
        'filename',
        'file_type',
        'file_size'
    ];

    /**
     * Get the email that this attachment belongs to
     */
    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }
} 