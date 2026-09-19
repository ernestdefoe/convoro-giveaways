<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forfeit + redraw when the winner can't answer the skill-testing question.
 *
 * `forfeited_user_ids` is published on the verification page: a verifier needs
 * it to reproduce the result, because the winner is the lowest
 * sha256(seed:user-id) among the entrants who have NOT forfeited.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('giveaways')) {
            return;
        }

        Schema::table('giveaways', function (Blueprint $table) {
            if (! Schema::hasColumn('giveaways', 'skill_attempt_limit')) {
                $table->unsignedTinyInteger('skill_attempt_limit')->default(0)->after('skill_answer');
            }
            if (! Schema::hasColumn('giveaways', 'skill_attempts')) {
                $table->unsignedTinyInteger('skill_attempts')->default(0)->after('skill_attempt_limit');
            }
            if (! Schema::hasColumn('giveaways', 'forfeited_user_ids')) {
                $table->text('forfeited_user_ids')->nullable()->after('skill_verified_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('giveaways')) {
            return;
        }

        Schema::table('giveaways', function (Blueprint $table) {
            foreach (['skill_attempt_limit', 'skill_attempts', 'forfeited_user_ids'] as $column) {
                if (Schema::hasColumn('giveaways', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
