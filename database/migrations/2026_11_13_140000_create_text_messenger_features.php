<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messenger_saved_spaces', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
        Schema::create('message_stars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at');
            $table->unique(['user_id', 'message_id']);
        });
        Schema::create('messenger_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->unique(['user_id', 'conversation_id']);
        });
        Schema::create('message_edits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('previous_body')->nullable();
            $table->text('new_body');
            $table->timestamp('created_at');
        });
        Schema::create('messenger_presence_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('session_id');
            $table->boolean('active')->default(false);
            $table->timestamp('last_seen_at');
            $table->timestamps();
            $table->unique(['user_id', 'session_id']);
            $table->index(['active', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messenger_presence_sessions');
        Schema::dropIfExists('message_edits');
        Schema::dropIfExists('messenger_drafts');
        Schema::dropIfExists('message_stars');
        Schema::dropIfExists('messenger_saved_spaces');
    }
};
