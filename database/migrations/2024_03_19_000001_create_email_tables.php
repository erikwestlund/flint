<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Create email_participants table
        Schema::create('email_participants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->boolean('is_valid_email')->default(false);
            $table->string('department')->nullable();
            $table->timestamps();
        });

        // Create emails table
        Schema::create('emails', function (Blueprint $table) {
            $table->id();
            $table->integer('seq_id')->nullable();
            $table->string('subject');
            $table->longText('text_full');
            $table->longText('text_body');
            $table->text('text_header');
            $table->foreignId('sender_id')->constrained('email_participants')->onDelete('cascade');
            $table->timestamp('timestamp');
            $table->boolean('has_attachments')->default(false);
            $table->string('department')->nullable();
            $table->string('pdf')->nullable();
            $table->string('bookmark')->nullable();
            $table->string('bookmark_title')->nullable();
            $table->boolean('is_canonical')->default(false);
            $table->string('email_n_in_bm')->nullable();
            $table->string('source_file')->nullable();
            $table->json('sender_discordance')->nullable()->comment('Tracks discrepancies between JSON sender and extracted sender. Structure: {json_sender: {name, email}, extracted_sender: {name, email}, used_sender: "json"|"extracted"}');
            $table->json('recipient_discordance')->nullable()->comment('Tracks discrepancies between JSON recipients and extracted recipients. Structure: {json_recipients: [], extracted_recipients: [], matches: [], mismatches: []}');
            $table->timestamps();
        });

        // Create email_recipients table
        Schema::create('email_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_id')->constrained('emails')->onDelete('cascade');
            $table->foreignId('employee_id')->constrained('email_participants')->onDelete('cascade');
            $table->boolean('is_cc')->default(false);
            $table->timestamps();
        });

        // Create email_attachments table
        Schema::create('email_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_id')->constrained('emails')->onDelete('cascade');
            $table->string('filename');
            $table->timestamps();
        });

        // Create email_duplicates table
        Schema::create('email_duplicates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_id')->constrained('emails')->onDelete('cascade');
            $table->string('duplicate_email_seq_id');
            $table->timestamps();

            $table->unique(['email_id', 'duplicate_email_seq_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_duplicates');
        Schema::dropIfExists('email_attachments');
        Schema::dropIfExists('email_recipients');
        Schema::dropIfExists('emails');
        Schema::dropIfExists('email_participants');
    }
}; 