<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('giveaways')) {
            return;
        }

        Schema::table('giveaways', function (Blueprint $table) {
            // Long-form terms & conditions. Many jurisdictions require the rules
            // (eligibility, how the winner is selected, deadlines, odds…) to be
            // published up front, and they can run long — hence a text column
            // rather than piggy-backing on the short description.
            if (! Schema::hasColumn('giveaways', 'rules')) {
                // mediumText, not text: 20 000 characters of rules can exceed
                // TEXT's 65 535 *bytes* once multi-byte characters are involved.
                $table->mediumText('rules')->nullable()->after('description');
            }

            // Optional skill-testing question. Where a pure game of chance would
            // count as an illegal lottery (e.g. Canada), entrants must answer a
            // question correctly, which makes entry a game of mixed skill.
            if (! Schema::hasColumn('giveaways', 'skill_question')) {
                $table->string('skill_question', 300)->nullable()->after('rules');
            }
            if (! Schema::hasColumn('giveaways', 'skill_answer')) {
                $table->string('skill_answer', 120)->nullable()->after('skill_question');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('giveaways')) {
            return;
        }

        Schema::table('giveaways', function (Blueprint $table) {
            foreach (['rules', 'skill_question', 'skill_answer'] as $column) {
                if (Schema::hasColumn('giveaways', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
