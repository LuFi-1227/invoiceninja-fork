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
        if (Schema::hasTable('clients') && ! Schema::hasColumn('clients', 'emit_nota_fiscal')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->boolean('emit_nota_fiscal')->default(true)->after('contact_method');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('clients') && Schema::hasColumn('clients', 'emit_nota_fiscal')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->dropColumn('emit_nota_fiscal');
            });
        }
    }
};
