<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    protected $fillable = [
        'name',
        'email',
        'department',
        'organization'
    ];

    /**
     * Get the emails where this employee is the sender
     */
    public function sentEmails(): HasMany
    {
        return $this->hasMany(Email::class, 'sender_id');
    }

    /**
     * Get the emails where this employee is a recipient
     */
    public function receivedEmails(): HasMany
    {
        return $this->hasMany(EmailRecipient::class);
    }
} 