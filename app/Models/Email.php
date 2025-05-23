<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Email extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'timestamp' => 'datetime',
        'has_attachments' => 'boolean',
        'is_canonical' => 'boolean',
        'attachments' => 'array',
        'duplicates' => 'array'
    ];

    /**
     * Get the sender of the email
     */
    public function sender()
    {
        return $this->belongsTo(EmailParticipant::class, 'sender_id');
    }

    /**
     * Get the recipients of the email
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(EmailRecipient::class);
    }

    /**
     * Get the attachments of the email
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(EmailAttachment::class);
    }

    /**
     * Get the canonical version of this email
     */
    public function canonical(): BelongsTo
    {
        return $this->belongsTo(Email::class, 'canonical_email_id');
    }

    /**
     * Get the duplicates of this email
     */
    public function duplicates(): HasMany
    {
        return $this->hasMany(EmailDuplicate::class);
    }

    /**
     * Get the canonical emails that this email is a duplicate of
     */
    public function canonicalOf(): BelongsToMany
    {
        return $this->belongsToMany(Email::class, 'email_duplicates', 'duplicate_email_id', 'canonical_email_id')
            ->withTimestamps();
    }

    /**
     * Mark this email as canonical and link it to its duplicates
     */
    public function markAsCanonical(array $duplicateIds): void
    {
        $this->update(['is_canonical' => true]);
        $this->duplicates()->sync($duplicateIds);
    }

    /**
     * Mark this email as a duplicate of a canonical email
     */
    public function markAsDuplicate(Email $canonical): void
    {
        $this->update(['is_canonical' => false]);
        $canonical->duplicates()->attach($this->id);
    }

    /**
     * Add a duplicate to this email
     */
    public function addDuplicate(string $duplicateSeqId): void
    {
        $this->duplicates()->create([
            'duplicate_email_seq_id' => $duplicateSeqId
        ]);
    }
} 