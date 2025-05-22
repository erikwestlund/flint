<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Create employees table
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->boolean('is_valid_email')->default(false);
            $table->string('department')->nullable();
            $table->string('organization')->nullable();
            $table->timestamps();
        });

        // Create emails table
        Schema::create('emails', function (Blueprint $table) {
            $table->id();
            $table->string('subject');
            $table->text('text_full');
            $table->text('text_body');
            $table->text('text_header');
            $table->foreignId('sender_id')->constrained('employees')->onDelete('cascade');
            $table->timestamp('timestamp');
            $table->boolean('has_attachments')->default(false);
            $table->string('department')->nullable();
            $table->string('pdf')->nullable();
            $table->string('bookmark')->nullable();
            $table->string('bookmark_title')->nullable();
            $table->boolean('is_canonical')->default(false);
            $table->string('email_n_in_bm')->nullable();
            $table->string('source_file')->nullable();
            $table->string('file_number')->nullable();
            $table->timestamps();
        });

        // Create email_recipients table
        Schema::create('email_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_id')->constrained('emails')->onDelete('cascade');
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->boolean('is_cc')->default(false);
            $table->timestamps();
        });

        // Create email_attachments table
        Schema::create('email_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_id')->constrained('emails')->onDelete('cascade');
            $table->string('filename');
            $table->string('file_type')->nullable();
            $table->integer('file_size')->nullable();
            $table->boolean('exists')->default(false);
            $table->string('path')->nullable();
            $table->timestamps();
        });

        // Create email_duplicates table
        Schema::create('email_duplicates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('canonical_email_id')->constrained('emails')->onDelete('cascade');
            $table->foreignId('duplicate_email_id')->constrained('emails')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['canonical_email_id', 'duplicate_email_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_duplicates');
        Schema::dropIfExists('email_attachments');
        Schema::dropIfExists('email_recipients');
        Schema::dropIfExists('emails');
        Schema::dropIfExists('employees');
    }
}; 