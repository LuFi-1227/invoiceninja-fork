<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2026. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\Asaas\AsaasIncomeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AsaasIncomeController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Authenticated endpoint to trigger Asaas incomes sync.
     * POST /api/v1/asaas/sync_incomes
     */
    public function sync(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $company = $user ? $user->company() : null;

        if (! $company) {
            $first = Company::first();
            if ($first) {
                $company = $first;
            } else {
                return response()->json(['message' => 'Nenhuma empresa ativa encontrada.'], 404);
            }
        }

        $options = $request->only([
            'days',
            'start_date',
            'finish_date',
            'all_time',
            'auto_create_client',
            'default_client_name',
        ]);

        $service = AsaasIncomeService::forCompany($company);
        $result = $service->sync($options);

        return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
    }

    /**
     * Webhook endpoint to trigger incomes sync from external callers or Asaas.
     * Match GET/POST /api/v1/webhooks/asaas/sync_incomes/{company_key}
     */
    public function webhookSync(Request $request, string $company_key): JsonResponse
    {
        $company = Company::query()->where('company_key', $company_key)->first();

        if (! $company) {
            return response()->json(['message' => 'Empresa não encontrada para a chave informada.'], 404);
        }

        $service = AsaasIncomeService::forCompany($company);
        $gateway = $service->gateway();

        if (! $gateway) {
            return response()->json(['message' => 'Nenhum gateway Asaas configurado para esta empresa.'], 400);
        }

        $expectedWebhookToken = trim((string) $gateway->getConfigField('webhookToken'));
        if (! empty($expectedWebhookToken)) {
            $receivedToken = $request->header('asaas-access-token')
                ?? $request->header('Asaas-Access-Token')
                ?? $request->query('token')
                ?? $request->input('token');

            if (! hash_equals($expectedWebhookToken, trim((string) $receivedToken))) {
                Log::warning("AsaasIncomeController: Webhook rejected for company {$company->id} - invalid webhook token");
                return response()->json(['message' => 'Token de autenticação do webhook inválido.'], 401);
            }
        }

        $options = $request->only([
            'days',
            'start_date',
            'finish_date',
            'all_time',
            'auto_create_client',
            'default_client_name',
        ]);

        $result = $service->sync($options);

        return response()->json($result, ($result['success'] ?? false) ? 200 : 500);
    }
}
