<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'contact_method')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('contact_method')->default('whatsapp_and_email')->after('phone');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'contact_method')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('contact_method');
            });
        }
    }
};
