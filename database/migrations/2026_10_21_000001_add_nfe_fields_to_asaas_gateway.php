<?php

use App\Models\Gateway;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $gateway = Gateway::where('key', '07e4d88e0bbf4ad1b7dfeb21434c76b7')->first();

        if ($gateway) {
            $fields = json_decode($gateway->fields) ?: new \stdClass();
            $fields->apiKey = $fields->apiKey ?? '';
            $fields->webhookToken = $fields->webhookToken ?? '';
            $fields->testMode = $fields->testMode ?? false;
            $fields->emitNfeAutomatically = $fields->emitNfeAutomatically ?? true;
            $fields->municipalServiceCode = $fields->municipalServiceCode ?? '';

            $gateway->fields = json_encode($fields);
            $gateway->save();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $gateway = Gateway::where('key', '07e4d88e0bbf4ad1b7dfeb21434c76b7')->first();

        if ($gateway) {
            $fields = json_decode($gateway->fields) ?: new \stdClass();
            unset($fields->emitNfeAutomatically, $fields->municipalServiceCode);

            $gateway->fields = json_encode($fields);
            $gateway->save();
        }
    }
};
