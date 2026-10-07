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

use App\Libraries\MultiDB;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class WhatsAppController extends BaseController
{

    /**
     * Get current WhatsApp configuration for the company.
     */
    public function getConfig(): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $service = new WhatsAppService($user->company());

        return response()->json([
            'is_configured' => $service->isConfigured(),
            'config' => $service->getConfig(),
        ]);
    }

    /**
     * Update WhatsApp configuration for the company.
     */
    public function saveConfig(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        $validated = $request->validate([
            'provider' => 'required|in:evolution,meta',
            'enabled' => 'boolean',
            'send_pdf' => 'boolean',
            'auto_send_on_create' => 'boolean',
            'message_template' => 'nullable|string',

            // Evolution API
            'evolution_api_url' => 'nullable|string',
            'evolution_api_key' => 'nullable|string',
            'evolution_instance' => 'nullable|string',

            // Meta Cloud API
            'meta_phone_number_id' => 'nullable|string',
            'meta_access_token' => 'nullable|string',
        ]);

        $service = new WhatsAppService($user->company());
        $service->saveSettings($validated);

        $testResult = $service->testConnection();

        return response()->json([
            'message' => 'Configurações de WhatsApp salvas com sucesso!',
            'test_connection' => $testResult,
            'config' => $service->getConfig(),
        ]);
    }

    /**
     * Test connection to the configured WhatsApp service.
     */
    public function testConnection(): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $service = new WhatsAppService($user->company());

        $result = $service->testConnection();

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Send a test message to a specific phone number.
     */
    public function sendTestMessage(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string',
            'message' => 'nullable|string',
        ]);

        /** @var \App\Models\User $user */
        $user = auth()->user();
        $service = new WhatsAppService($user->company());

        $phone = (string) $request->input('phone');
        $message = (string) ($request->input('message') ?: "Olá! Esta é uma mensagem de teste do Invoice Ninja via WhatsApp.");

        $result = $service->sendTestMessage($phone, $message);

        return response()->json($result, ($result['success'] ?? false) ? 200 : 400);
    }

    /**
     * Send an invoice to a client via WhatsApp.
     */
    public function sendInvoice(Request $request, Invoice $invoice): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        // Ensure invoice belongs to user's company
        if ($invoice->company_id !== $user->company()->id) {
            return response()->json(['error' => 'Acesso negado à fatura.'], 403);
        }

        $service = new WhatsAppService($user->company());

        if (!$service->isConfigured()) {
            return response()->json([
                'error' => 'WhatsApp não configurado ou desativado. Acesse Configurações > WhatsApp para cadastrar sua EvolutionAPI ou MetaAPI.',
            ], 400);
        }

        $phone = $request->input('phone');
        $customMessage = $request->input('message');
        $sendPdf = $request->has('send_pdf') ? $request->boolean('send_pdf') : null;

        $result = $service->sendInvoice($invoice, $phone, $customMessage, $sendPdf);

        return response()->json($result, ($result['success'] ?? false) ? 200 : 400);
    }

    /**
     * Resolve company from request (auth user, query token, company_key, or first company).
     */
    protected function resolveCompany(Request $request): ?Company
    {
        if (auth()->check() && auth()->user()->company()) {
            return auth()->user()->company();
        }

        if ($request->filled('token')) {
            $data = Cache::get('whatsapp_connect_' . $request->input('token'));
            if (isset($data['company_key'])) {
                MultiDB::findAndSetDbByCompanyKey($data['company_key']);
                return Company::where('company_key', $data['company_key'])->first();
            }
        }

        if ($request->filled('company_key')) {
            $companyKey = (string) $request->input('company_key');
            MultiDB::findAndSetDbByCompanyKey($companyKey);
            return Company::where('company_key', $companyKey)->first();
        }

        return Company::first();
    }

    /**
     * Get QR Code to connect WhatsApp instance (Evolution API).
     */
    public function getQrCode(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return response()->json(['error' => 'Empresa não encontrada.'], 404);
        }

        $service = new WhatsAppService($company);
        $result = $service->getQrCode();

        return response()->json($result, ($result['success'] ?? false) ? 200 : 400);
    }

    /**
     * Get live connection state of WhatsApp instance.
     */
    public function getStatus(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return response()->json(['error' => 'Empresa não encontrada.'], 404);
        }

        $service = new WhatsAppService($company);
        $status = $service->getConnectionStatus();

        return response()->json($status);
    }

    /**
     * Disconnect / Logout WhatsApp instance.
     */
    public function disconnect(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return response()->json(['error' => 'Empresa não encontrada.'], 404);
        }

        $service = new WhatsAppService($company);
        $result = $service->disconnect();

        return response()->json($result, ($result['success'] ?? false) ? 200 : 400);
    }

    /**
     * Generate a temporary signed connect URL for embedding or opening the pairing UI.
     */
    public function getConnectUrl(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return response()->json(['error' => 'Empresa não encontrada.'], 404);
        }

        $token = Str::random(32);
        Cache::put('whatsapp_connect_' . $token, [
            'company_key' => $company->company_key,
        ], now()->addHours(2));

        $url = route('whatsapp.connect', ['token' => $token]);

        return response()->json([
            'token' => $token,
            'connect_url' => $url,
        ]);
    }

    /**
     * Render the interactive web view for pairing WhatsApp with QR Code.
     */
    public function connectView(Request $request): View
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            abort(404, 'Empresa não encontrada.');
        }

        $service = new WhatsAppService($company);
        $config = $service->getConfig();
        $status = $service->getConnectionStatus();

        return view('whatsapp.connect', [
            'company' => $company,
            'config' => $config,
            'status' => $status,
            'token' => $request->input('token'),
            'company_key' => $company->company_key,
        ]);
    }
}

