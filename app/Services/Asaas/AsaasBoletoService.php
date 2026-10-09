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

use App\Models\CompanyGateway;
use App\Models\Invoice;
use App\Models\PaymentHash;
use App\PaymentDrivers\Asaas\AsaasApi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Resolves (or generates) an Asaas Boleto for an invoice so it can be
 * attached to outgoing WhatsApp messages and emails.
 *
 * A pending Boleto is reused whenever possible; a new one is only created
 * when there is no reusable payment or the previous one expired/was cancelled.
 */
class AsaasBoletoService
{
    /** Gateway key of the Asaas payment library. */
    public const GATEWAY_KEY = '07e4d88e0bbf4ad1b7dfeb21434c76b7';

    public function __construct(private Invoice $invoice) {}

    public static function forInvoice(Invoice $invoice): self
    {
        return new self($invoice);
    }

    /**
     * The company's active Asaas gateway, if any.
     */
    public function gateway(): ?CompanyGateway
    {
        if (! $this->invoice->company) {
            return null;
        }

        return $this->invoice->company
            ->company_gateways()
            ->where('is_deleted', 0)
            ->where('gateway_key', self::GATEWAY_KEY)
            ->first();
    }

    /**
     * Whether a Boleto can be resolved for this invoice.
     */
    public function isAvailable(): bool
    {
        $gateway = $this->gateway();

        return $gateway !== null
            && ! empty($gateway->getConfigField('apiKey'))
            && (float) $this->invoice->balance > 0;
    }

    /**
     * Resolve boleto metadata (linha digitável, barcode, url, due date).
     */
    public function resolve(bool $forceNew = false): ?array
    {
        if (! $this->isAvailable()) {
            return null;
        }

        try {
            $api = $this->api($this->gateway());

            $hash = $forceNew ? null : $this->reusableHash();
            $payment = null;
            $paymentId = $hash?->data->asaas_payment_id ?? null;

            if ($paymentId) {
                $payment = $this->safePayment($api, (string) $paymentId);
                $status = $payment['status'] ?? null;

                // Cannot reuse a closed/expired/receivable charge.
                if (! $payment || in_array($status, ['OVERDUE', 'CANCELLED', 'REFUNDED', 'RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'], true)) {
                    $hash = null;
                    $payment = null;
                    $paymentId = null;
                }
            }

            if (! $paymentId) {
                $hash = $this->createHash();
                $payment = $this->createBoleto($api, $hash);
                $paymentId = (string) ($payment['id'] ?? '');
            }

            if (empty($paymentId)) {
                return null;
            }

            $identification = $this->safeIdentification($api, $paymentId);

            return [
                'success' => true,
                'payment_id' => $paymentId,
                'hash' => $hash?->hash,
                'url' => $payment['bankSlipUrl'] ?? ($hash?->data->asaas_bank_slip_url ?? ''),
                'linha_digitavel' => $identification['identificationField'] ?? ($hash?->data->asaas_linha_digitavel ?? ''),
                'barcode' => $identification['barCode'] ?? '',
                'due_date' => $payment['dueDate'] ?? ($hash?->data->asaas_due_date ?? null),
            ];
        } catch (\Throwable $e) {
            Log::warning('AsaasBoletoService resolve failed: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Resolve the boleto and download its PDF bytes when available.
     */
    public function resolvePdf(): ?array
    {
        $boleto = $this->resolve();

        if (! $boleto) {
            return null;
        }

        $boleto['filename'] = 'Boleto_' . ($this->invoice->number ?: $this->invoice->id) . '.pdf';

        if (! empty($boleto['url'])) {
            try {
                $response = Http::timeout(30)->get($boleto['url']);

                if ($response->successful() && strlen($response->body()) > 0) {
                    $boleto['pdf'] = $response->body();
                }
            } catch (\Throwable $e) {
                Log::warning('AsaasBoletoService pdf download failed: ' . $e->getMessage());
            }
        }

        return $boleto;
    }

    private function api(CompanyGateway $gateway): AsaasApi
    {
        return new AsaasApi(
            (string) $gateway->getConfigField('apiKey'),
            (bool) $gateway->getConfigField('testMode'),
            (string) $gateway->getConfigField('webhookToken')
        );
    }

    private function reusableHash(): ?PaymentHash
    {
        return PaymentHash::query()
            ->where('fee_invoice_id', $this->invoice->id)
            ->whereNull('payment_id')
            ->whereNotNull('data->asaas_payment_id')
            ->orderByDesc('id')
            ->first();
    }

    private function createHash(): PaymentHash
    {
        $amount = round((float) $this->invoice->balance, $this->precision());

        return PaymentHash::create([
            'hash' => Str::random(32),
            'data' => [
                'amount_with_fee' => $amount,
                'fee_net' => 0,
                'invoices' => [
                    [
                        'invoice_id' => $this->invoice->hashed_id,
                        'amount' => $amount,
                        'invoice_number' => $this->invoice->number,
                        'pre_payment' => (bool) $this->invoice->is_proforma,
                    ],
                ],
                'asaas_source' => 'outbound_boleto',
            ],
            'fee_total' => 0,
            'fee_invoice_id' => $this->invoice->id,
        ]);
    }

    private function createBoleto(AsaasApi $api, PaymentHash $hash): array
    {
        $customerId = $api->createOrUpdateCustomer($this->invoice->client);
        $dueDate = $this->resolveDueDate();

        $payment = $api->createPayment([
            'customer' => $customerId,
            'billingType' => 'BOLETO',
            'value' => (float) $hash->data->amount_with_fee,
            'dueDate' => $dueDate,
            'description' => 'Fatura ' . ($this->invoice->number ?: '#' . $this->invoice->id),
            'externalReference' => $hash->hash,
            'postalService' => false,
        ]);

        $identification = $this->safeIdentification($api, (string) $payment['id']);

        $data = (array) $hash->data;
        $data['asaas_payment_id'] = $payment['id'];
        $data['asaas_status'] = $payment['status'] ?? 'PENDING';
        $data['asaas_linha_digitavel'] = $identification['identificationField'] ?? '';
        $data['asaas_bank_slip_url'] = $payment['bankSlipUrl'] ?? '';
        $data['asaas_due_date'] = $dueDate;

        $hash->data = $data;
        $hash->save();

        return $payment;
    }

    private function resolveDueDate(): string
    {
        $today = now()->format('Y-m-d');
        $dueDate = $this->invoice->due_date
            ? Carbon::parse($this->invoice->due_date)->format('Y-m-d')
            : now()->addDays(3)->format('Y-m-d');

        if ($dueDate < $today) {
            $dueDate = now()->addDays(3)->format('Y-m-d');
        }

        return $dueDate;
    }

    private function precision(): int
    {
        try {
            return (int) ($this->invoice->client->currency()->precision ?? 2);
        } catch (\Throwable $e) {
            return 2;
        }
    }

    private function safePayment(AsaasApi $api, string $paymentId): ?array
    {
        try {
            return $api->getPayment($paymentId);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function safeIdentification(AsaasApi $api, string $paymentId): array
    {
        try {
            return $api->getIdentificationField($paymentId);
        } catch (\Throwable $e) {
            return [];
        }
    }
}
