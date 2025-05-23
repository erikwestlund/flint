<?php

namespace App\Jobs;

use App\Models\EmailParticipant;
use App\Models\Email;
use App\Models\EmailRecipient;
use App\Models\EmailAttachment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class ImportEmails
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $directory;
    protected ?array $specificFiles;
    protected array $data;

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

            $this->data = json_decode(File::get($file->getPathname()), true);
            
            // Extract file number from filename (e.g., "00001" from "00001.json")
            $fileNumber = pathinfo($file->getFilename(), PATHINFO_FILENAME);
            
            // First try to extract sender from the email body
            $extractedSender = EmailParticipant::extractFromHeader($this->data['text_header']);
            
            // Create or find both potential senders
            $headerSender = null;
            $jsonSender = null;
            
            if ($extractedSender) {
                $headerSenders = EmailParticipant::createOrFindEmployee(
                    $extractedSender['email'],
                    $extractedSender['name'],
                    $extractedSender['department'] ?? null,  // Only use department from extraction
                    $this->data['text_header']
                );
                $headerSender = $headerSenders[0] ?? null;
            }

            // Always try to create/find the JSON sender
            if (!empty($this->data['sender'])) {
                $jsonSenders = EmailParticipant::createOrFindEmployee(
                    '',  // JSON doesn't provide email
                    $this->data['sender'],
                    null,  // Don't pass department for JSON sender
                    $this->data['text_header']
                );
                $jsonSender = $jsonSenders[0] ?? null;
            }

            // Log the discrepancy if we have both senders
            if ($headerSender && $jsonSender && $headerSender->id !== $jsonSender->id) {
                Log::info('Sender discrepancy found', [
                    'file' => $file->getFilename(),
                    'header_sender' => [
                        'id' => $headerSender->id,
                        'name' => $headerSender->name,
                        'email' => $headerSender->email
                    ],
                    'json_sender' => [
                        'id' => $jsonSender->id,
                        'name' => $jsonSender->name,
                        'email' => $jsonSender->email
                    ]
                ]);
            }

            // Use JSON sender if available, otherwise fall back to header sender
            $sender = $jsonSender ?? $headerSender;

            // Skip if we don't have a valid sender
            if (!$sender) {
                Log::warning('No valid sender found for email', [
                    'file' => $file->getFilename(),
                    'header' => $this->data['text_header'] ?? null,
                    'json_sender' => $this->data['sender'] ?? null
                ]);
                continue;
            }

            // Process sender discordance only if we have both sources
            $senderDiscordance = null;
            if (!empty($this->data['sender']) && $headerSender) {
                $senderDiscordance = [
                    'json_sender' => $this->data['sender'],
                    'extracted_sender' => [
                        'name' => $headerSender->name,
                        'email' => $headerSender->email
                    ]
                ];
            }

            // This is a new email
            $email = $this->createEmail($sender, $file->getFilename(), $fileNumber);
            
            // Process recipients from both JSON and header
            $headerRecipients = $this->extractAllEmails($this->data['text_header']);
            $jsonRecipients = !empty($this->data['recipients_to']) ? $this->data['recipients_to'] : [];
            
            $recipientDiscordance = null;
            
            // Only check for discordance if we have both sources
            if (!empty($headerRecipients) && !empty($jsonRecipients)) {
                $missing = [];
                foreach ($headerRecipients as $recipient) {
                    $found = false;
                    foreach ($jsonRecipients as $jsonRecipient) {
                        if (stripos(trim($jsonRecipient), trim($recipient['name'])) !== false) {
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $missing[] = [
                            'name' => $recipient['name'],
                            'email' => $recipient['email']
                        ];
                    }
                }
                if (!empty($missing)) {
                    $recipientDiscordance = ['missing_from_json' => $missing];
                }
            }
            
            // Process header recipients
            foreach ($headerRecipients as $recipient) {
                $employees = EmailParticipant::createOrFindEmployee(
                    $recipient['email'],
                    $recipient['name'],
                    $recipient['department'] ?? null,  // Only use department from extraction
                    $this->data['text_header']
                );
                
                foreach ($employees as $employee) {
                    // Create the recipient relationship
                    $email->recipients()->create([
                        'employee_id' => $employee->id,
                        'is_cc' => false
                    ]);
                }
            }
            
            // Process JSON recipients
            foreach ($jsonRecipients as $jsonRecipient) {
                $employees = EmailParticipant::createOrFindEmployee(
                    '',  // JSON doesn't provide email
                    trim($jsonRecipient) ?: 'Unknown',  // Ensure we have a non-null name
                    null,  // Don't pass department for JSON recipients
                    $this->data['text_header']
                );
                
                foreach ($employees as $employee) {
                    // Create the recipient relationship if not already created
                    if (!$email->recipients()->where('employee_id', $employee->id)->exists()) {
                        $email->recipients()->create([
                            'employee_id' => $employee->id,
                            'is_cc' => false
                        ]);
                    }
                }
            }
            
            // Process attachments
            if (!empty($this->data['attachments'])) {
                foreach ($this->data['attachments'] as $attachment) {
                    $email->attachments()->create([
                        'filename' => trim($attachment)
                    ]);
                }
            }
            
            // Process duplicates
            if (!empty($this->data['duplicates'])) {
                foreach ($this->data['duplicates'] as $duplicate) {
                    $email->addDuplicate($duplicate);
                }
            }
            
            // Update the email with discordance info
            $email->update([
                'sender_discordance' => $senderDiscordance,
                'recipient_discordance' => $recipientDiscordance
            ]);
            
            // Set canonical status based on JSON flag
            if ($this->data['canonical'] ?? false) {
                $email->update(['is_canonical' => true]);
            }
        }
    }

    private function createEmail(EmailParticipant $sender, string $filename, string $seqId): Email
    {
        // Extract timestamp from the email data
        $timestamp = null;
        if (!empty($this->data['timestamp'])) {
            try {
                $timestamp = Carbon::parse($this->data['timestamp']);
            } catch (\Exception $e) {
                Log::warning('Invalid timestamp in email data', [
                    'file' => $filename,
                    'timestamp' => $this->data['timestamp']
                ]);
            }
        }

        // Create the email record with all required fields
        return Email::create([
            'sender_id' => $sender->id,
            'seq_id' => trim($seqId),
            'subject' => trim($this->data['subject'] ?? ''),
            'text_full' => trim($this->data['text_full'] ?? ''),
            'text_body' => trim($this->data['text_body'] ?? ''),
            'text_header' => trim($this->data['text_header'] ?? ''),
            'timestamp' => $timestamp ?? now(),
            'has_attachments' => !empty($this->data['attachments']),
            'department' => trim($this->data['department'] ?? ''),
            'pdf' => trim($this->data['pdf'] ?? ''),
            'bookmark' => trim($this->data['bookmark'] ?? ''),
            'bookmark_title' => trim($this->data['bookmark_title'] ?? ''),
            'is_canonical' => $this->data['canonical'] ?? false,
            'email_n_in_bm' => $this->data['email_n_in_bm'] ?? null,
            'source_file' => trim($filename),
            'sender_discordance' => null,
            'recipient_discordance' => null
        ]);
    }

    protected function cleanAndValidateEmail(?string $email, ?string $name = null): array
    {
        if (!$email) {
            return ['email' => '', 'is_valid' => false, 'name' => trim($name ?? '')];
        }

        // Clean the email
        $email = trim($email);
        $email = trim($email, ':');
        $email = trim($email);
        
        // Log the email before validation
        Log::info('Validating email', [
            'email' => $email,
            'name' => trim($name ?? '')
        ]);
        
        // Validate the email - use a more permissive regex that matches the standard
        $isValid = preg_match('/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $email) === 1;

        Log::info('Email validation result', [
            'email' => $email,
            'is_valid' => $isValid,
            'name' => trim($name ?? '')
        ]);

        return [
            'email' => $isValid ? $email : '',
            'is_valid' => $isValid,
            'name' => trim($name ?? '')
        ];
    }

    protected function extractEmail(string $header, ?string $name = null): ?string
    {
        $email = null;
        if ($name) {
            // For recipients, look for "To: Name (email@domain.com)" format
            if (preg_match("/To: " . preg_quote(trim($name), '/') . "\s*\(([^)]+@[^)]+)\)/", $header, $matches)) {
                $email = trim($matches[1]);
            }
            // Try alternative format with angle brackets
            else if (preg_match("/To: " . preg_quote(trim($name), '/') . "\s*<([^>]+@[^>]+)>/", $header, $matches)) {
                $email = trim($matches[1]);
            }
        } else {
            // For sender, look for "From: Name (email@domain.com)" format
            if (preg_match('/From:\s*([^(]+)\s*\(([^)]+@[^)]+)\)/', $header, $matches)) {
                $email = trim($matches[2]);
            }
            // Try alternative format with angle brackets
            else if (preg_match('/From:\s*([^<]+)\s*<([^>]+@[^>]+)>/', $header, $matches)) {
                $email = trim($matches[2]);
            }
            // Try just the email part if no name is found
            else if (preg_match('/From:.*?([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/', $header, $matches)) {
                $email = trim($matches[1]);
            }
        }

        // Validate the email before returning
        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }

        Log::info('Email extraction failed', [
            'name' => trim($name ?? ''),
            'email' => $email,
            'header' => $header,
            'matches' => $matches ?? []
        ]);

        return null;
    }

    protected function extractOrganization(string $header, ?string $name = null): ?string
    {
        if ($name) {
            // Escape special regex characters in the name
            $escapedName = preg_quote($name, '/');
            if (preg_match("/$escapedName\s*<([^>]+)>/", $header, $matches)) {
                return $matches[1];
            }
        } else {
            if (preg_match('/From:.*?<([^>]+)>/', $header, $matches)) {
                return $matches[1];
            }
        }
        return null;
    }

    protected function extractAllEmails(string $header): array
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

    protected function extractSenderFromBody(string $textHeader): ?array
    {
        $sender = null;
        
        // ONLY look for From: field in the header
        if (preg_match('/From:\s*([^(]+)\s*\(([^)]+@[^)]+)\)/', $textHeader, $matches)) {
            $sender = [
                'name' => trim($matches[1]),
                'email' => trim($matches[2])
            ];
        }
        // Try alternative format with angle brackets
        else if (preg_match('/From:\s*([^<]+)\s*<([^>]+@[^>]+)>/', $textHeader, $matches)) {
            $sender = [
                'name' => trim($matches[1]),
                'email' => trim($matches[2])
            ];
        }

        Log::info('Extracted sender from body', [
            'sender' => $sender,
            'header' => $textHeader
        ]);

        return $sender;
    }
}