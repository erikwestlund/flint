<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

class EmailParticipant extends Model
{
    protected $fillable = [
        'name',
        'email',
        'department'
    ];

    /**
     * Get the emails sent by this participant
     */
    public function sentEmails(): HasMany
    {
        return $this->hasMany(Email::class, 'sender_id');
    }

    /**
     * Get the emails where this participant is a recipient
     */
    public function receivedEmails(): HasMany
    {
        return $this->hasMany(EmailRecipient::class, 'employee_id');
    }

    /**
     * Extract sender information from email header
     */
    public static function extractFromHeader(?string $header): ?array
    {
        if (!$header) {
            return null;
        }

        // Look for "From: Name (DEPT)" format without email
        if (preg_match('/From:\s*([^(]+?)\s*\(([^)]+)\)(?:\s*$|\s*Sent:)/', $header, $matches)) {
            $name = trim($matches[1]);
            // Skip if name is too long or contains email-like content
            if (strlen($name) > 100 || strpos($name, '@') !== false || strpos($name, 'Subject:') !== false) {
                return null;
            }
            return [
                'name' => $name,
                'email' => '',  // No email provided
                'department' => trim($matches[2])
            ];
        }

        // Look for "From: Name (DEPT) (email@domain.com)" format
        if (preg_match('/From:\s*([^(]+?)\s*\(([^)]+)\)\s*\(([^)]+@[^)]+)\)/', $header, $matches)) {
            $name = trim($matches[1]);
            // Skip if name is too long or contains email-like content
            if (strlen($name) > 100 || strpos($name, '@') !== false || strpos($name, 'Subject:') !== false) {
                return null;
            }
            return [
                'name' => $name,
                'email' => trim($matches[3]),
                'department' => trim($matches[2])
            ];
        }
        
        // Look for "From: Name (email@domain.com) (DEPT)" format
        if (preg_match('/From:\s*([^(]+?)\s*\(([^)]+@[^)]+)\)\s*(?:\(([^)]+)\))?/', $header, $matches)) {
            $name = trim($matches[1]);
            // Skip if name is too long or contains email-like content
            if (strlen($name) > 100 || strpos($name, '@') !== false || strpos($name, 'Subject:') !== false) {
                return null;
            }
            return [
                'name' => $name,
                'email' => trim($matches[2]),
                'department' => isset($matches[3]) ? trim($matches[3]) : null
            ];
        }
        
        // Try alternative format with angle brackets
        if (preg_match('/From:\s*([^<]+?)\s*<([^>]+@[^>]+)>\s*(?:\(([^)]+)\))?/', $header, $matches)) {
            $name = trim($matches[1]);
            // Skip if name is too long or contains email-like content
            if (strlen($name) > 100 || strpos($name, '@') !== false || strpos($name, 'Subject:') !== false) {
                return null;
            }
            return [
                'name' => $name,
                'email' => trim($matches[2]),
                'department' => isset($matches[3]) ? trim($matches[3]) : null
            ];
        }

        return null;
    }

    /**
     * Extract all emails from header
     */
    public static function extractAllEmails(string $header): array
    {
        $emails = [];
        
        // Extract all emails from To: fields only
        if (preg_match_all('/To: ([^(]+)\s*\(([^)]+@[^)]+)\)/', $header, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $email = trim($match[2]);
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $emails[] = [
                        'name' => trim($match[1]),
                        'email' => $email,
                        'department' => null,  // No department in this format
                        'email_format' => 'parentheses'
                    ];
                }
            }
        }
        
        // Also try angle bracket format for To: fields
        if (preg_match_all('/To: ([^<]+)\s*<([^>]+@[^>]+)>/', $header, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $email = trim($match[2]);
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $emails[] = [
                        'name' => trim($match[1]),
                        'email' => $email,
                        'department' => null,  // No department in this format
                        'email_format' => 'angle_brackets'
                    ];
                }
            }
        }

        Log::info('Extracted recipient emails', [
            'emails' => $emails,
            'header' => $header
        ]);

        return $emails;
    }

    /**
     * Create or find an email participant
     */
    public static function createOrFindEmployee(string $email, string $name, ?string $department = null, ?string $header = null): array
    {
        $employees = [];
        
        // Split names by semicolons
        $names = array_map('trim', explode(';', $name));
        
        foreach ($names as $name) {
            // Skip if name contains email-like content or is too long
            if (strlen($name) > 100 || strpos($name, '@') !== false || strpos($name, 'Subject:') !== false) {
                continue;
            }
            
            // Extract department from name if it's in parentheses at the end
            $departmentFromName = null;
            
            // Check for (XXX) format - this is a department
            if (preg_match('/^(.*?)\s*\(([^)]+)\)$/', $name, $matches)) {
                $name = trim($matches[1]);
                $departmentFromName = trim($matches[2]);
            }
            
            // Clean up the name
            $name = trim($name);
            $name = preg_replace('/[^\p{L}\p{N}\s.\'-]/u', '', $name); // Keep letters, numbers, spaces, dots, hyphens, and apostrophes
            $name = preg_replace('/\s+/', ' ', $name); // Normalize spaces
            
            // Skip if name is empty after cleanup or still too long
            if (empty($name) || strlen($name) > 100) {
                continue;
            }
            
            // Try to find existing participant
            $employee = self::where('name', $name)
                ->when($email, function($query) use ($email) {
                    return $query->orWhere('email', $email);
                })
                ->first();
            
            if (!$employee) {
                // Create new participant
                $employee = self::create([
                    'name' => $name,
                    'email' => $email,
                    'department' => $departmentFromName ?? $department  // Only use provided department if no department from name
                ]);
            } else {
                // Update department if provided and different
                $updates = [];
                
                // Only update department if we have a new one from the name
                if ($departmentFromName && $employee->department !== $departmentFromName) {
                    $updates['department'] = $departmentFromName;
                }
                // Or if we have a new department from the parameter and no existing department
                else if ($department && !$employee->department) {
                    $updates['department'] = $department;
                }
                
                if (!empty($updates)) {
                    $employee->update($updates);
                }
            }
            
            $employees[] = $employee;
        }
        
        return $employees;
    }
} 