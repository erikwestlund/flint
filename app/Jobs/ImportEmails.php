<?php

namespace App\Jobs;

use App\Models\Employee;
use App\Models\Email;
use App\Models\EmailRecipient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;

class ImportEmails implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $directory;
    protected ?array $specificFiles;

    public function __construct(string $directory, ?array $specificFiles = null)
    {
        $this->directory = $directory;
        $this->specificFiles = $specificFiles;
    }

    public function handle(): void
    {
        if ($this->specificFiles) {
            $files = array_map(function($file) {
                return new \SplFileInfo($this->directory . '/' . $file);
            }, $this->specificFiles);
        } else {
            $files = File::files($this->directory);
        }

        foreach ($files as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }

            $data = json_decode(File::get($file->getPathname()), true);
            
            // Extract file number from filename (e.g., "00001" from "00001.json")
            $fileNumber = pathinfo($file->getFilename(), PATHINFO_FILENAME);
            
            // Create or find sender
            $senderEmail = $this->cleanAndValidateEmail($this->extractEmail($data['text_header']));
            $sender = Employee::firstOrCreate(
                ['name' => $data['sender']],
                [
                    'email' => $senderEmail['email'],
                    'is_valid_email' => $senderEmail['is_valid'],
                    'department' => $data['department'] ?? null,
                    'organization' => $this->extractOrganization($data['text_header'])
                ]
            );

            // Check for existing email with same content
            $existingEmail = Email::where('text_full', $data['text_full'])
                ->where('sender_id', $sender->id)
                ->where('timestamp', date('Y-m-d H:i:s', $data['timestamp']))
                ->first();

            if ($existingEmail) {
                // This is a duplicate
                if ($existingEmail->is_canonical) {
                    // Existing email is canonical, mark this as duplicate
                    $email = $this->createEmail($data, $sender, $file->getFilename(), $fileNumber);
                    $email->markAsDuplicate($existingEmail);
                } else {
                    // Existing email is not canonical, check if it has a canonical version
                    $canonical = $existingEmail->canonicalOf()->first();
                    if ($canonical) {
                        // Link to existing canonical
                        $email = $this->createEmail($data, $sender, $file->getFilename(), $fileNumber);
                        $email->markAsDuplicate($canonical);
                    } else {
                        // Make the existing email canonical and this one duplicate
                        $email = $this->createEmail($data, $sender, $file->getFilename(), $fileNumber);
                        $existingEmail->markAsCanonical([$email->id]);
                    }
                }
            } else {
                // This is a new email
                $email = $this->createEmail($data, $sender, $file->getFilename(), $fileNumber);
                
                // If this email has duplicates listed in the data
                if (!empty($data['duplicates'])) {
                    // First ensure all duplicate emails exist
                    $validDuplicateIds = [];
                    foreach ($data['duplicates'] as $duplicateId) {
                        // Check if the duplicate email exists
                        $duplicateEmail = Email::where('file_number', $duplicateId)->first();
                        if ($duplicateEmail) {
                            $validDuplicateIds[] = $duplicateEmail->id;
                        }
                    }
                    
                    if (!empty($validDuplicateIds)) {
                        $email->markAsCanonical($validDuplicateIds);
                    }
                } elseif ($data['canonical'] ?? false) {
                    $email->update(['is_canonical' => true]);
                }
            }
        }
    }

    protected function createEmail(array $data, Employee $sender, string $filename, string $fileNumber): Email
    {
        $email = Email::create([
            'subject' => $data['subject'],
            'text_full' => $data['text_full'],
            'text_body' => $data['text_body'],
            'text_header' => $data['text_header'],
            'sender_id' => $sender->id,
            'timestamp' => date('Y-m-d H:i:s', $data['timestamp']),
            'has_attachments' => !empty($data['attachments']),
            'department' => $data['department'] ?? null,
            'pdf' => $data['pdf'] ?? null,
            'bookmark' => $data['bookmark'] ?? null,
            'bookmark_title' => $data['bookmark_title'] ?? null,
            'is_canonical' => false,
            'email_n_in_bm' => $data['email_n_in_bm'] ?? null,
            'source_file' => $filename,
            'file_number' => $fileNumber
        ]);

        // Create recipients
        if (!empty($data['recipients_to'])) {
            foreach ($data['recipients_to'] as $recipientName) {
                $recipientEmail = $this->cleanAndValidateEmail($this->extractEmail($data['text_header'], $recipientName));
                $recipient = Employee::firstOrCreate(
                    ['name' => $recipientName],
                    [
                        'email' => $recipientEmail['email'],
                        'is_valid_email' => $recipientEmail['is_valid'],
                        'department' => $data['department'] ?? null,
                        'organization' => $this->extractOrganization($data['text_header'], $recipientName)
                    ]
                );

                EmailRecipient::create([
                    'email_id' => $email->id,
                    'employee_id' => $recipient->id,
                    'is_cc' => false
                ]);
            }
        }

        return $email;
    }

    protected function cleanAndValidateEmail(?string $email): array
    {
        if (!$email) {
            return ['email' => null, 'is_valid' => false];
        }

        // Clean the email
        $email = trim($email);
        $email = trim($email, ':');
        $email = trim($email);
        
        // Validate the email
        $validator = Validator::make(['email' => $email], [
            'email' => 'required|email'
        ]);

        return [
            'email' => $email,
            'is_valid' => !$validator->fails()
        ];
    }

    protected function extractEmail(string $header, ?string $name = null): ?string
    {
        if ($name) {
            if (preg_match("/$name\s*\(([^)]+@[^)]+)\)/", $header, $matches)) {
                return $matches[1];
            }
        } else {
            if (preg_match('/From:.*?\(([^)]+@[^)]+)\)/', $header, $matches)) {
                return $matches[1];
            }
        }
        return null;
    }

    protected function extractOrganization(string $header, ?string $name = null): ?string
    {
        if ($name) {
            if (preg_match("/$name\s*\(([^)]+)\)/", $header, $matches)) {
                return $matches[1];
            }
        } else {
            if (preg_match('/From:.*?\(([^)]+)\)/', $header, $matches)) {
                return $matches[1];
            }
        }
        return null;
    }
} 