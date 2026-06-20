<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('giveaways')) {
            Schema::create('giveaways', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->string('prize');
                $table->text('description')->nullable();
                $table->timestamp('ends_at')->nullable();
                // Commit–reveal fairness: seed_hash is published at creation (the
                // commitment); the raw seed is only revealed once the draw runs.
                $table->string('seed', 64);
                $table->string('seed_hash', 64);
                $table->unsignedInteger('winner_user_id')->nullable();
                $table->timestamp('drawn_at')->nullable();
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('giveaway_entries')) {
            Schema::create('giveaway_entries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('giveaway_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('user_id');
                $table->timestamp('created_at')->nullable();
                $table->unique(['giveaway_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('giveaway_entries');
        Schema::dropIfExists('giveaways');
    }
};
