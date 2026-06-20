<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('giveaways') && ! Schema::hasColumn('giveaways', 'image_path')) {
            Schema::table('giveaways', function (Blueprint $table) {
                $table->string('image_path', 600)->nullable()->after('description');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('giveaways') && Schema::hasColumn('giveaways', 'image_path')) {
            Schema::table('giveaways', function (Blueprint $table) {
                $table->dropColumn('image_path');
            });
        }
    }
};
