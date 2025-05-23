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
                            {--all : Import all files}
                            {--range= : Import files from a range}';

    protected $description = 'Import emails from JSON files';

    public function handle()
    {
        $directory = database_path('seeders/seed_data/emails');

        if ($this->option('file')) {
            $this->info("Importing single file: {$this->option('file')}");
            ImportEmails::dispatch($directory, [$this->option('file')]);
            $this->info("Job dispatched for {$this->option('file')}");
        } elseif ($this->option('files')) {
            $files = $this->option('files');
            $this->info("Importing specific files: " . implode(', ', $files));
            ImportEmails::dispatch($directory, $files);
            $this->info("Jobs dispatched for specified files");
        } elseif ($this->option('all')) {
            $this->info("Importing all files from directory: {$directory}");
            $files = File::files($directory);
            $jsonFiles = array_filter($files, fn($file) => $file->getExtension() === 'json');
            $fileNames = array_map(fn($file) => $file->getFilename(), $jsonFiles);
            ImportEmails::dispatch($directory, $fileNames);
            $this->info("Jobs dispatched for all files");
        } elseif ($this->option('range')) {
            $range = explode('-', $this->option('range'));
            $start = (int)$range[0];
            $end = (int)$range[1];
            $files = [];
            for ($i = $start; $i <= $end; $i++) {
                $files[] = sprintf('%05d.json', $i);
            }
            $this->info("Importing files from range: " . implode(', ', $files));
            ImportEmails::dispatch($directory, $files);
            $this->info("Jobs dispatched for specified range");
        } else {
            $this->error('Please specify either --file, --files, --all, or --range option');
            return 1;
        }

        $this->info('Import process completed');
        return 0;
    }
} 