<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The skill-testing question is put to the drawn winner, not to every entrant,
 * so the giveaway needs to record when that winner answered it correctly —
 * that timestamp is the evidence the prize was awarded on skill.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('giveaways') && ! Schema::hasColumn('giveaways', 'skill_verified_at')) {
            Schema::table('giveaways', function (Blueprint $table) {
                $table->timestamp('skill_verified_at')->nullable()->after('drawn_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('giveaways') && Schema::hasColumn('giveaways', 'skill_verified_at')) {
            Schema::table('giveaways', function (Blueprint $table) {
                $table->dropColumn('skill_verified_at');
            });
        }
    }
};
