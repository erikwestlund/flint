<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailRecipient extends Model
{
    protected $fillable = [
        'email_id',
        'employee_id',
        'is_cc'
    ];

    protected $casts = [
        'is_cc' => 'boolean'
    ];

    /**
     * Get the email that this recipient belongs to
     */
    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    /**
     * Get the employee who is a recipient
     */
    public function employee()
    {
        return $this->belongsTo(EmailParticipant::class);
    }
} 