<?php

use App\Models\Gateway;
use App\Models\GatewayType;
use App\Models\Company;
use App\Models\Client;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        \Illuminate\Database\Eloquent\Model::unguard();

        if (Schema::hasTable('gateways')) {
            $gateway = Gateway::find(70);

            $fields = new \stdClass();
            $fields->apiKey = '';
            $fields->webhookToken = '';
            $fields->testMode = false;

            if (!$gateway) {
                $gateway = new Gateway();
                $gateway->id = 70;
                $gateway->name = 'Asaas';
                $gateway->key = '07e4d88e0bbf4ad1b7dfeb21434c76b7';
                $gateway->provider = 'Asaas';
                $gateway->is_offsite = false;
                $gateway->sort_order = 32;
                $gateway->fields = json_encode($fields);
                $gateway->visible = true;
                $gateway->site_url = 'https://www.asaas.com';
                $gateway->default_gateway_type_id = GatewayType::BANK_TRANSFER;
                $gateway->save();
            } else {
                $gateway->name = 'Asaas';
                $gateway->key = '07e4d88e0bbf4ad1b7dfeb21434c76b7';
                $gateway->provider = 'Asaas';
                $gateway->site_url = 'https://www.asaas.com';
                $gateway->fields = json_encode($fields);
                $gateway->default_gateway_type_id = GatewayType::BANK_TRANSFER;
                $gateway->save();
            }
        }

        // Set default CPF / CNPJ for client1 custom field in companies if not configured
        if (Schema::hasTable('companies')) {
            $companies = Company::all();
            foreach ($companies as $company) {
                $customFields = (object) ($company->custom_fields ?? []);
                if (empty($customFields->client1)) {
                    $customFields->client1 = 'CPF / CNPJ';
                    $company->custom_fields = $customFields;
                    $company->save();
                }
            }
        }

        // Encrypt existing plaintext custom_value1 in clients if present
        if (Schema::hasTable('clients')) {
            $clients = DB::table('clients')
                ->whereNotNull('custom_value1')
                ->where('custom_value1', '!=', '')
                ->get();

            foreach ($clients as $client) {
                try {
                    decrypt($client->custom_value1);
                } catch (\Throwable $e) {
                    // Was stored unencrypted: encrypt it now
                    DB::table('clients')
                        ->where('id', $client->id)
                        ->update(['custom_value1' => encrypt($client->custom_value1)]);
                }
            }
        }

        \Illuminate\Database\Eloquent\Model::reguard();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('gateways')) {
            Gateway::where('key', '07e4d88e0bbf4ad1b7dfeb21434c76b7')->delete();
        }
    }
};
