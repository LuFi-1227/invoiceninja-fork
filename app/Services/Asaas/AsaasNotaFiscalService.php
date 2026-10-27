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

namespace App\Services\Asaas;

use App\Jobs\Mail\NinjaMailerJob;
use App\Jobs\Util\SystemLogger;
use App\Mail\Client\NotaFiscalMail;
use App\Mail\NinjaMailerObject;
use App\Models\Activity;
use App\Models\CompanyGateway;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentHash;
use App\Models\SystemLog;
use App\PaymentDrivers\Asaas\AsaasApi;
use App\Services\WhatsApp\WhatsAppService;
use App\Utils\Traits\MakesHash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AsaasNotaFiscalService
{
    use MakesHash;

    private CompanyGateway $companyGateway;
    private AsaasApi $api;
    private ?string $lastError = null;

    public static function forInvoice(Invoice $invoice): ?self
    {
        if (!$invoice->company) {
            return null;
        }

        $companyGateway = $invoice->company
            ->company_gateways()
            ->where('is_deleted', 0)
            ->whereNull('deleted_at')
            ->where('gateway_key', AsaasBoletoService::GATEWAY_KEY)
            ->orderByDesc('id')
            ->first();

        if (!$companyGateway || empty($companyGateway->getConfigField('apiKey'))) {
            return null;
        }

        return new self($companyGateway);
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function __construct(CompanyGateway $companyGateway)
    {
        $this->companyGateway = $companyGateway;
        $apiKey = (string) $this->companyGateway->getConfigField('apiKey');
        $testMode = (bool) $this->companyGateway->getConfigField('testMode');
        $webhookToken = (string) $this->companyGateway->getConfigField('webhookToken');
        $this->api = new AsaasApi($apiKey, $testMode, $webhookToken);
    }

    /**
     * Request creation / scheduling of municipal Nota Fiscal (NFS-e) in Asaas for a confirmed payment.
     *
     * @param Invoice $invoice
     * @param string $paymentId Asaas payment ID (e.g. pay_...)
     * @param float $amount
     * @param string|null $customerAsaasId
     * @return array|null Asaas invoice response
     */
    public function requestInvoiceForPayment(Invoice $invoice, ?string $paymentId = null, ?float $amount = null, ?string $customerAsaasId = null, bool $force = false): ?array
    {
        try {
            $this->lastError = null;

            // Check if automatic NFS-e emission is enabled for this gateway (unless forced manually)
            if (!$force) {
                $emitNfe = $this->companyGateway->getConfigField('emitNfeAutomatically');
                if ($emitNfe === false || $emitNfe === '0' || $emitNfe === 'false') {
                    Log::info("Asaas automatic NFS-e emission is disabled in company gateway settings. Skipping for invoice {$invoice->id}");
                    return null;
                }

                if ($invoice->client && method_exists($invoice->client, 'shouldEmitNotaFiscal') && !$invoice->client->shouldEmitNotaFiscal()) {
                    Log::info("Asaas automatic NFS-e emission skipped for invoice {$invoice->id}: client {$invoice->client->id} has emit_nota_fiscal disabled.");
                    return null;
                }

                $user = $invoice->assigned_user
                    ?: $invoice->user
                    ?: ($invoice->user_id ? \App\Models\User::find($invoice->user_id) : null);

                if ($user && method_exists($user, 'shouldEmitNotaFiscal') && !$user->shouldEmitNotaFiscal()) {
                    Log::info("Asaas automatic NFS-e emission skipped for invoice {$invoice->id}: user {$user->id} has emit_nota_fiscal disabled.");
                    return null;
                }
            }

            $customer = $customerAsaasId ?: ($invoice->client->asaas_id ?: $this->api->createOrUpdateCustomer($invoice->client));

            $val = $amount !== null ? (float) $amount : (float) ($invoice->amount > 0 ? $invoice->amount : $invoice->balance);

            // Build service description from invoice line items or default
            $description = "Serviços prestados referentes à Fatura #{$invoice->number}.";
            if ($invoice->line_items && count($invoice->line_items) > 0) {
                $notes = collect($invoice->line_items)
                    ->map(fn($item) => is_array($item) ? ($item['notes'] ?? '') : ($item->notes ?? ''))
                    ->filter()
                    ->take(3)
                    ->implode(', ');

                if (!empty($notes)) {
                    $description .= " Detalhes: " . $notes;
                }
            }

            $description = mb_substr($description, 0, 950);

            $payload = [
                'customer' => (string) $customer,
                'serviceDescription' => $description,
                'value' => $val,
                'effectiveDate' => now()->format('Y-m-d'),
                'externalReference' => (string) ($invoice->hashed_id ?: $invoice->number),
            ];

            if (!empty($paymentId)) {
                $payload['payment'] = (string) $paymentId;
            }

            // Optional municipal service code from gateway configuration
            $municipalServiceCode = (string) ($this->companyGateway->getConfigField('municipalServiceCode') ?: '');
            if (!empty($municipalServiceCode)) {
                $payload['municipalServiceCode'] = $municipalServiceCode;
            }

            Log::info("Requesting Asaas Nota Fiscal for Invoice #{$invoice->number}, paymentId={$paymentId}, amount={$amount}");

            $response = $this->api->createInvoice($payload);

            SystemLogger::dispatch(
                [
                    'event' => 'ASAAS_NF_REQUESTED',
                    'invoice_id' => $invoice->id,
                    'payment_id' => $paymentId,
                    'asaas_response' => $response,
                ],
                SystemLog::CATEGORY_GATEWAY_RESPONSE,
                SystemLog::EVENT_GATEWAY_SUCCESS,
                SystemLog::TYPE_ASAAS,
                $invoice->client,
                $invoice->company,
            );

            Log::info("Asaas Nota Fiscal requested successfully: ID " . ($response['id'] ?? 'unknown') . " for payment {$paymentId}");

            return $response;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            Log::error("Failed to request Asaas Nota Fiscal for invoice {$invoice->id}: " . $e->getMessage());

            SystemLogger::dispatch(
                [
                    'event' => 'ASAAS_NF_REQUEST_FAILED',
                    'invoice_id' => $invoice->id,
                    'payment_id' => $paymentId,
                    'error' => $e->getMessage(),
                ],
                SystemLog::CATEGORY_GATEWAY_RESPONSE,
                SystemLog::EVENT_GATEWAY_FAILURE,
                SystemLog::TYPE_ASAAS,
                $invoice->client,
                $invoice->company,
            );

            $this->createInvoiceActivity(
                $invoice,
                "⚠️ Falha ao solicitar emissão da Nota Fiscal no Asaas: " . $e->getMessage()
            );

            return null;
        }
    }

    /**
     * Handle the INVOICE_AUTHORIZED webhook event sent by Asaas when the city hall issues the NFS-e.
     *
     * @param array $invoiceData The 'invoice' object from webhook payload
     * @return array
     */
    public function handleInvoiceAuthorizedWebhook(array $invoiceData): array
    {
        $nfId = (string) ($invoiceData['id'] ?? '');
        $paymentId = (string) ($invoiceData['payment'] ?? '');
        $externalRef = (string) ($invoiceData['externalReference'] ?? '');
        $status = (string) ($invoiceData['status'] ?? '');

        Log::info("Processing Asaas INVOICE_AUTHORIZED: nfId={$nfId}, paymentId={$paymentId}, externalRef={$externalRef}, status={$status}");

        $invoice = $this->resolveInvoice($paymentId, $externalRef);

        if (!$invoice) {
            Log::warning("Could not find matching invoice for Asaas Nota Fiscal webhook: paymentId={$paymentId}, externalRef={$externalRef}");
            return ['success' => false, 'message' => 'Invoice not found'];
        }

        // Determine payment method / billing type from existing payment record or Asaas data
        $billingType = $this->resolveBillingType($invoice, $paymentId);

        // 1. Send the Nota Fiscal to the client via their chosen method
        $dispatchResult = $this->sendNotaFiscalToClient($invoice, $invoiceData, $billingType);

        // 2. Attach the Nota Fiscal to invoice documents so it's viewable in panel and Client Portal
        $this->attachNotaFiscalToInvoice($invoice, $invoiceData);

        SystemLogger::dispatch(
            [
                'event' => 'ASAAS_NF_AUTHORIZED_DISPATCHED',
                'invoice_id' => $invoice->id,
                'asaas_nf_id' => $nfId,
                'nf_number' => $invoiceData['number'] ?? '',
                'dispatch_result' => $dispatchResult,
            ],
            SystemLog::CATEGORY_GATEWAY_RESPONSE,
            SystemLog::EVENT_GATEWAY_SUCCESS,
            SystemLog::TYPE_ASAAS,
            $invoice->client,
            $invoice->company,
        );

        return [
            'success' => true,
            'invoice_id' => $invoice->id,
            'nf_number' => $invoiceData['number'] ?? '',
            'dispatch' => $dispatchResult,
        ];
    }

    /**
     * Handle INVOICE_ERROR webhook event when the municipal city hall rejects NFS-e emission.
     *
     * @param array $invoiceData
     * @return array
     */
    public function handleInvoiceErrorWebhook(array $invoiceData): array
    {
        $nfId = (string) ($invoiceData['id'] ?? '');
        $paymentId = (string) ($invoiceData['payment'] ?? '');
        $externalRef = (string) ($invoiceData['externalReference'] ?? '');
        $statusDescription = (string) ($invoiceData['statusDescription'] ?? ($invoiceData['errors'][0]['description'] ?? 'Erro desconhecido retornado pela prefeitura'));

        Log::warning("Asaas INVOICE_ERROR received: nfId={$nfId}, paymentId={$paymentId}, description={$statusDescription}");

        $invoice = $this->resolveInvoice($paymentId, $externalRef);

        if ($invoice) {
            SystemLogger::dispatch(
                [
                    'event' => 'ASAAS_NF_ERROR',
                    'invoice_id' => $invoice->id,
                    'asaas_nf_id' => $nfId,
                    'payment_id' => $paymentId,
                    'error' => $statusDescription,
                ],
                SystemLog::CATEGORY_GATEWAY_RESPONSE,
                SystemLog::EVENT_GATEWAY_FAILURE,
                SystemLog::TYPE_ASAAS,
                $invoice->client,
                $invoice->company,
            );

            $this->createInvoiceActivity(
                $invoice,
                "⚠️ Falha na emissão da Nota Fiscal no Asaas: {$statusDescription}"
            );
        }

        return [
            'success' => true,
            'status' => 'error_recorded',
            'message' => $statusDescription,
        ];
    }

    /**
     * Download the NFS-e PDF and attach it to the invoice documents.
     */
    public function attachNotaFiscalToInvoice(Invoice $invoice, array $nfData): ?Document
    {
        $pdfUrl = $nfData['pdfUrl'] ?? '';
        $nfNumber = $nfData['number'] ?? ($invoice->number ?: $invoice->id);

        if (empty($pdfUrl)) {
            return null;
        }

        try {
            $existingDoc = $invoice->documents()
                ->where('name', "Nota_Fiscal_{$nfNumber}.pdf")
                ->first();

            if ($existingDoc) {
                return $existingDoc;
            }

            $response = Http::timeout(30)->get($pdfUrl);
            if (!$response->successful() || empty($response->body())) {
                Log::warning("Could not download Nota Fiscal PDF from {$pdfUrl}");
                return null;
            }

            $pdfBytes = $response->body();
            $hash = Str::random(40) . '.pdf';
            $companyId = $invoice->company_id;
            $disk = config('filesystems.default', 'local');
            $storagePath = "documents/{$companyId}/{$hash}";

            Storage::disk($disk)->put($storagePath, $pdfBytes);

            $document = new Document();
            $document->user_id = $invoice->user_id ?: ($invoice->company ? $invoice->company->owner()->id : 1);
            $document->company_id = $invoice->company_id;
            $document->url = $storagePath;
            $document->name = "Nota_Fiscal_{$nfNumber}.pdf";
            $document->type = 'pdf';
            $document->disk = $disk;
            $document->hash = $hash;
            $document->size = strlen($pdfBytes);
            $document->is_public = 1;

            $invoice->documents()->save($document);

            // Log activity on invoice
            $this->createInvoiceActivity(
                $invoice,
                "Nota Fiscal de Serviço (NFS-e Nº {$nfNumber}) emitida com sucesso pelo Asaas e anexada aos documentos."
            );

            Log::info("Attached Nota Fiscal {$nfNumber} to invoice {$invoice->number} as document {$document->id}");

            return $document;
        } catch (\Throwable $e) {
            Log::error("Failed to attach Nota Fiscal to invoice {$invoice->id}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Manually request/emit Nota Fiscal for an invoice.
     */
    public function emitNotaFiscalManually(Invoice $invoice): array
    {
        $payment = $invoice->payments()->latest()->first();
        $paymentId = $payment?->transaction_reference;

        if (empty($paymentId)) {
            $hash = PaymentHash::where('invoice_id', $invoice->id)->first();
            $paymentId = $hash?->data->asaas_payment_id ?? $hash?->data->asaas_pix_payment_id ?? null;
        }

        $amount = (float) ($payment ? $payment->amount : ($invoice->amount > 0 ? $invoice->amount : $invoice->balance));

        $response = $this->requestInvoiceForPayment(
            $invoice,
            $paymentId ? (string) $paymentId : null,
            $amount,
            null,
            true
        );

        if (!$response) {
            return [
                'success' => false,
                'message' => $this->lastError ?: 'Erro ao comunicar com a API do Asaas.',
            ];
        }

        if (($response['status'] ?? '') === 'AUTHORIZED' && !empty($response['pdfUrl'])) {
            $this->sendNotaFiscalToClient($invoice, $response);
            $this->attachNotaFiscalToInvoice($invoice, $response);
        }

        return [
            'success' => true,
            'data' => $response,
            'message' => 'Solicitação de emissão de Nota Fiscal enviada com sucesso!',
        ];
    }

    /**
     * Dispatch the generated Nota Fiscal to the client via the selected contact method (WhatsApp, Email, or Both).
     *
     * @param Invoice $invoice
     * @param array $nfData
     * @param string|null $billingType
     * @return array
     */
    public function sendNotaFiscalToClient(Invoice $invoice, array $nfData, ?string $billingType = null): array
    {
        $user = $invoice->assigned_user
            ?: $invoice->user
            ?: ($invoice->user_id ? \App\Models\User::find($invoice->user_id) : null)
            ?: ($invoice->company ? $invoice->company->owner() : null);

        $client = $invoice->client;

        $contactMethod = ($client && $client->hasContactMethod())
            ? $client->contact_method
            : ($user ? ($user->contact_method ?: 'whatsapp_and_email') : 'whatsapp_and_email');
        $method = strtolower(trim((string) $contactMethod));

        $shouldSendEmail = str_contains($method, 'email') || $method === 'both' || $method === 'all' || empty($method);
        $shouldSendWhatsApp = str_contains($method, 'whatsapp') || $method === 'both' || $method === 'all';

        $paymentMethodLabel = $this->formatBillingTypeLabel($billingType);

        $results = [
            'email' => null,
            'whatsapp' => null,
        ];

        // 1. Send via WhatsApp if enabled
        if ($shouldSendWhatsApp) {
            try {
                $whatsAppService = new WhatsAppService($invoice->company);
                if ($whatsAppService->isConfigured()) {
                    $waResult = $whatsAppService->sendNotaFiscal($invoice, $nfData, $paymentMethodLabel);
                    $results['whatsapp'] = $waResult;
                    Log::info("Dispatched Nota Fiscal via WhatsApp for invoice {$invoice->number}: " . json_encode($waResult));
                } else {
                    $results['whatsapp'] = ['success' => false, 'message' => 'WhatsApp not configured'];
                }
            } catch (\Throwable $e) {
                Log::error("Failed to send Nota Fiscal via WhatsApp for invoice {$invoice->number}: " . $e->getMessage());
                $results['whatsapp'] = ['success' => false, 'error' => $e->getMessage()];
            }
        }

        // 2. Send via Email if enabled
        if ($shouldSendEmail) {
            try {
                $contact = $invoice->client->contacts()->where('send_email', true)->first()
                    ?: $invoice->client->contacts()->first();

                if ($contact && !empty($contact->email)) {
                    $nmo = new NinjaMailerObject();
                    $nmo->mailable = new NotaFiscalMail($invoice, $nfData, $paymentMethodLabel);
                    $nmo->to_user = $contact;
                    $nmo->company = $invoice->company;
                    $nmo->settings = $invoice->company->settings;

                    NinjaMailerJob::dispatch($nmo);

                    $results['email'] = ['success' => true, 'recipient' => $contact->email];
                    Log::info("Dispatched Nota Fiscal via Email for invoice {$invoice->number} to {$contact->email}");
                } else {
                    $results['email'] = ['success' => false, 'message' => 'Client has no contact email'];
                }
            } catch (\Throwable $e) {
                Log::error("Failed to send Nota Fiscal via Email for invoice {$invoice->number}: " . $e->getMessage());
                $results['email'] = ['success' => false, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Resolve Invoice by externalReference or Asaas paymentId.
     */
    private function resolveInvoice(string $paymentId, string $externalRef): ?Invoice
    {
        // 1. Try externalReference matching PaymentHash
        if (!empty($externalRef)) {
            $paymentHash = PaymentHash::where('hash', $externalRef)->first();
            if ($paymentHash && $paymentHash->fee_invoice) {
                return $paymentHash->fee_invoice;
            }

            // Try decoding externalRef as invoice hashed id
            try {
                $decodedId = $this->decodePrimaryKey($externalRef);
                if ($decodedId) {
                    $invoice = Invoice::find($decodedId);
                    if ($invoice) {
                        return $invoice;
                    }
                }
            } catch (\Throwable $e) {}

            // Try matching invoice number directly
            $invoiceByNumber = Invoice::where('number', $externalRef)
                ->where('company_id', $this->companyGateway->company_id)
                ->first();
            if ($invoiceByNumber) {
                return $invoiceByNumber;
            }
        }

        // 2. Try by transaction_reference on recorded Payment
        if (!empty($paymentId)) {
            $payment = Payment::where('transaction_reference', $paymentId)
                ->where('company_id', $this->companyGateway->company_id)
                ->first();

            if ($payment && $payment->invoices->isNotEmpty()) {
                return $payment->invoices->first();
            }

            // Try matching asaas_payment_id inside payment_hash data
            $paymentHash = PaymentHash::where('data->asaas_payment_id', $paymentId)->first();
            if ($paymentHash && $paymentHash->fee_invoice) {
                return $paymentHash->fee_invoice;
            }
        }

        return null;
    }

    /**
     * Determine billing type for the invoice.
     */
    private function resolveBillingType(Invoice $invoice, string $paymentId): string
    {
        if (!empty($paymentId)) {
            try {
                $asaasPayment = $this->api->getPayment($paymentId);
                if (!empty($asaasPayment['billingType'])) {
                    return (string) $asaasPayment['billingType'];
                }
            } catch (\Throwable $e) {}
        }

        $payment = $invoice->payments()->latest()->first();
        if ($payment && $payment->payment_type) {
            return $payment->payment_type->name;
        }

        return 'Pix';
    }

    /**
     * Format friendly label for payment method.
     */
    private function formatBillingTypeLabel(?string $billingType): string
    {
        $type = strtoupper(trim((string) $billingType));

        return match ($type) {
            'PIX' => 'Pix',
            'BOLETO' => 'Boleto Bancário',
            'CREDIT_CARD' => 'Cartão de Crédito',
            default => !empty($billingType) ? $billingType : 'Pix / Boleto',
        };
    }

    /**
     * Create an Activity on the invoice.
     */
    private function createInvoiceActivity(Invoice $invoice, string $notes): void
    {
        try {
            $activity = new Activity();
            $activity->invoice_id = $invoice->id;
            $activity->client_id = $invoice->client_id;
            $activity->user_id = $invoice->user_id ?: ($invoice->company ? $invoice->company->owner()->id : 1);
            $activity->company_id = $invoice->company_id;
            $activity->account_id = $invoice->company ? $invoice->company->account_id : null;
            $activity->activity_type_id = Activity::UPDATE_INVOICE;
            $activity->notes = $notes;
            $activity->ip = ' ';
            $activity->is_system = true;
            $activity->save();
        } catch (\Throwable $e) {
            Log::warning("Could not create invoice activity: " . $e->getMessage());
        }
    }
}
