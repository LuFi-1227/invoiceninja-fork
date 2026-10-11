<?php

use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backfill the client1 custom field label so the CPF / CNPJ field
     * is displayed (and therefore required) for existing companies.
     *
     * The value must include the field type ("label|type"); without it the
     * frontend falls back to "multi_line_text" (a textarea). "single_line_text"
     * renders a regular single line text input.
     */
    public function up(): void
    {
        if (! Schema::hasTable('companies')) {
            return;
        }

        Model::unguard();

        Company::query()->each(function (Company $company) {
            $custom_fields = (object) ($company->custom_fields ?? []);
            $client1 = $custom_fields->client1 ?? '';

            if (empty($client1)) {
                $custom_fields->client1 = 'CPF / CNPJ|single_line_text';
                $company->custom_fields = $custom_fields;
                $company->saveQuietly();

                return;
            }

            if (! str_contains($client1, '|')) {
                $custom_fields->client1 = $client1.'|single_line_text';
                $company->custom_fields = $custom_fields;
                $company->saveQuietly();
            }
        });

        Model::reguard();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
