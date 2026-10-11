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
        if (Schema::hasTable('clients') && ! Schema::hasColumn('clients', 'asaas_id')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->string('asaas_id')->nullable()->after('id_number');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('clients') && Schema::hasColumn('clients', 'asaas_id')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->dropColumn('asaas_id');
            });
        }
    }
};
