<?php

namespace App\Console\Commands;

use App\Jobs\ImportEmails;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class ImportEmailsCommand extends Command
{
    protected $signature = 'emails:import 
                            {--file= : Import a specific file}
                            {--files=* : Import specific files}
                            {--all : Import all files}';

    protected $description = 'Import emails from JSON files';

    public function handle()
    {
        $directory = database_path('seeders/seed_data/emails');

        if ($this->option('file')) {
            $this->info("Importing single file: {$this->option('file')}");
            try {
                $job = new ImportEmails($directory, [$this->option('file')]);
                $job->handle();
                $this->info("✓ Successfully processed {$this->option('file')}");
            } catch (\Exception $e) {
                $this->error("✗ Failed to process {$this->option('file')}");
                $this->error("Error: " . $e->getMessage());
                $this->error("Stack trace: " . $e->getTraceAsString());
                Log::error("Email import failed", [
                    'file' => $this->option('file'),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        } elseif ($this->option('files')) {
            $files = $this->option('files');
            $this->info("Importing specific files: " . implode(', ', $files));
            foreach ($files as $file) {
                try {
                    $job = new ImportEmails($directory, [$file]);
                    $job->handle();
                    $this->info("✓ Successfully processed {$file}");
                } catch (\Exception $e) {
                    $this->error("✗ Failed to process {$file}");
                    $this->error("Error: " . $e->getMessage());
                    $this->error("Stack trace: " . $e->getTraceAsString());
                    Log::error("Email import failed", [
                        'file' => $file,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }
        } elseif ($this->option('all')) {
            $this->info("Importing all files from directory: {$directory}");
            $files = File::files($directory);
            $jsonFiles = array_filter($files, fn($file) => $file->getExtension() === 'json');
            foreach ($jsonFiles as $file) {
                try {
                    $job = new ImportEmails($directory, [$file->getFilename()]);
                    $job->handle();
                    $this->info("✓ Successfully processed {$file->getFilename()}");
                } catch (\Exception $e) {
                    $this->error("✗ Failed to process {$file->getFilename()}");
                    $this->error("Error: " . $e->getMessage());
                    $this->error("Stack trace: " . $e->getTraceAsString());
                    Log::error("Email import failed", [
                        'file' => $file->getFilename(),
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }
        } else {
            $this->error('Please specify either --file, --files, or --all option');
            return 1;
        }

        $this->info('Import process completed');
        return 0;
    }
} 