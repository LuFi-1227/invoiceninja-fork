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
 * Resolves (or generates) the Asaas charges that back an invoice so the
 * Boleto can be attached to outgoing WhatsApp messages/emails and the
 * barcode + Pix QR Code can be plotted on the system generated PDF.
 *
 * Two independent charges are created when Asaas is enabled:
 *   - KIND_BOLETO: a BOLETO charge (bank slip), used for the boleto PDF
 *     and for the barcode / "linha digitável".
 *   - KIND_PIX: a PIX charge, used for the Pix QR Code and copy-paste.
 *
 * A pending charge is reused whenever possible; a new one is only created
 * when there is no reusable payment or the previous one expired/was cancelled.
 */
class AsaasBoletoService
{
    /** Gateway key of the Asaas payment library. */
    public const GATEWAY_KEY = '07e4d88e0bbf4ad1b7dfeb21434c76b7';

    public const KIND_BOLETO = 'boleto';
    public const KIND_PIX = 'pix';

    /** Statuses that make a charge impossible to reuse. */
    private const CLOSED_STATUSES = ['OVERDUE', 'CANCELLED', 'REFUNDED', 'RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'];

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
     * Whether an Asaas charge can be resolved for this invoice.
     */
    public function isAvailable(): bool
    {
        $gateway = $this->gateway();

        return $gateway !== null
            && ! empty($gateway->getConfigField('apiKey'))
            && (float) $this->invoice->balance > 0;
    }

    /**
     * Resolve the Boleto charge metadata (linha digitável, barcode, url, due date).
     */
    public function resolve(bool $forceNew = false): ?array
    {
        if (! $this->isAvailable()) {
            return null;
        }

        try {
            $api = $this->api($this->gateway());

            $hash = $forceNew ? null : $this->reusableHash(self::KIND_BOLETO);
            $payment = null;
            $paymentId = $hash?->data->asaas_payment_id ?? null;

            if ($paymentId) {
                $payment = $this->safePayment($api, (string) $paymentId);
                $status = $payment['status'] ?? null;

                if (! $payment || in_array($status, self::CLOSED_STATUSES, true)) {
                    $hash = null;
                    $payment = null;
                    $paymentId = null;
                }
            }

            if (! $paymentId) {
                $hash = $this->createHash(self::KIND_BOLETO);
                $payment = $this->createBoletoCharge($api, $hash);
                $paymentId = (string) ($payment['id'] ?? '');
            }

            if (empty($paymentId)) {
                return null;
            }

            $identification = $this->safeIdentification($api, $paymentId);
            $stored = (array) $hash->data;

            return [
                'success' => true,
                'payment_id' => $paymentId,
                'hash' => $hash?->hash,
                'url' => $payment['bankSlipUrl'] ?? ($stored['asaas_bank_slip_url'] ?? ''),
                'linha_digitavel' => $identification['identificationField'] ?? ($stored['asaas_linha_digitavel'] ?? ''),
                'barcode' => $identification['barCode'] ?? ($stored['asaas_barcode'] ?? ''),
                'due_date' => $payment['dueDate'] ?? ($stored['asaas_due_date'] ?? null),
                'value' => $stored['amount_with_fee'] ?? round((float) $this->invoice->balance, $this->precision()),
                'status' => $payment['status'] ?? ($stored['asaas_status'] ?? 'PENDING'),
            ];
        } catch (\Throwable $e) {
            Log::warning('AsaasBoletoService resolve failed: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Resolve the Pix charge (QR base64 image + copy-paste string).
     */
    public function resolvePix(bool $forceNew = false): ?array
    {
        if (! $this->isAvailable()) {
            return null;
        }

        try {
            $api = $this->api($this->gateway());

            $hash = $forceNew ? null : $this->reusableHash(self::KIND_PIX);
            $payment = null;
            $paymentId = $hash?->data->asaas_pix_payment_id ?? null;

            if ($paymentId) {
                $payment = $this->safePayment($api, (string) $paymentId);
                $status = $payment['status'] ?? null;

                if (! $payment || in_array($status, self::CLOSED_STATUSES, true)) {
                    $hash = null;
                    $payment = null;
                    $paymentId = null;
                }
            }

            if (! $paymentId) {
                $hash = $this->createHash(self::KIND_PIX);
                $payment = $this->createPixCharge($api, $hash);
                $paymentId = (string) ($payment['id'] ?? '');
            }

            if (empty($paymentId)) {
                return null;
            }

            $stored = (array) $hash->data;

            return [
                'success' => true,
                'payment_id' => $paymentId,
                'hash' => $hash?->hash,
                'encoded_image' => $stored['asaas_pix_encoded_image'] ?? '',
                'payload' => $stored['asaas_pix_payload'] ?? '',
                'expiration_date' => $stored['asaas_pix_expiration_date'] ?? null,
                'due_date' => $payment['dueDate'] ?? ($stored['asaas_pix_due_date'] ?? null),
                'value' => $stored['amount_with_fee'] ?? round((float) $this->invoice->balance, $this->precision()),
                'status' => $payment['status'] ?? ($stored['asaas_pix_status'] ?? 'PENDING'),
            ];
        } catch (\Throwable $e) {
            Log::warning('AsaasBoletoService resolvePix failed: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Resolve both the Boleto and the Pix in a single call.
     */
    public function all(bool $forceNew = false): array
    {
        return [
            'available' => $this->isAvailable(),
            'boleto' => $this->resolve($forceNew),
            'pix' => $this->resolvePix($forceNew),
        ];
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

    /**
     * Latest reusable charge of the given kind (boleto or pix).
     */
    private function reusableHash(string $kind): ?PaymentHash
    {
        $query = PaymentHash::query()
            ->where('fee_invoice_id', $this->invoice->id)
            ->whereNull('payment_id');

        if ($kind === self::KIND_PIX) {
            $query->where('data->asaas_payment_kind', self::KIND_PIX)
                ->whereNotNull('data->asaas_pix_payment_id');
        } else {
            $query->where(fn ($q) => $q
                ->where('data->asaas_payment_kind', self::KIND_BOLETO)
                ->orWhereNull('data->asaas_payment_kind'))
                ->whereNotNull('data->asaas_payment_id');
        }

        return $query->orderByDesc('id')->first();
    }

    private function createHash(string $kind): PaymentHash
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
                'asaas_payment_kind' => $kind,
            ],
            'fee_total' => 0,
            'fee_invoice_id' => $this->invoice->id,
        ]);
    }

    /**
     * Create (and store) the BOLETO charge for the invoice.
     */
    private function createBoletoCharge(AsaasApi $api, PaymentHash $hash): array
    {
        $payment = $this->storePayment(
            $api,
            $hash,
            'BOLETO',
            'asaas_payment_id',
            'asaas_status',
        );

        $paymentId = (string) ($payment['id'] ?? '');
        $identification = $paymentId ? $this->safeIdentification($api, $paymentId) : [];

        $data = (array) $hash->data;
        $data['asaas_linha_digitavel'] = $identification['identificationField'] ?? '';
        $data['asaas_barcode'] = $identification['barCode'] ?? '';
        $data['asaas_bank_slip_url'] = $payment['bankSlipUrl'] ?? '';

        $hash->data = $data;
        $hash->save();

        return $payment;
    }

    /**
     * Create (and store) the PIX charge for the invoice.
     */
    private function createPixCharge(AsaasApi $api, PaymentHash $hash): array
    {
        $payment = $this->storePayment(
            $api,
            $hash,
            'PIX',
            'asaas_pix_payment_id',
            'asaas_pix_status',
        );

        $paymentId = (string) ($payment['id'] ?? '');
        $pix = $paymentId ? $this->safePix($api, $paymentId) : [];

        $data = (array) $hash->data;
        $data['asaas_pix_encoded_image'] = $pix['encodedImage'] ?? '';
        $data['asaas_pix_payload'] = $pix['payload'] ?? '';
        $data['asaas_pix_expiration_date'] = $pix['expirationDate'] ?? null;

        $hash->data = $data;
        $hash->save();

        return $payment;
    }

    /**
     * Create a charge of the given billing type and persist its reference.
     */
    private function storePayment(AsaasApi $api, PaymentHash $hash, string $billingType, string $idKey, string $statusKey): array
    {
        try {
            $customerId = $api->createOrUpdateCustomer($this->invoice->client);
            $dueDate = $this->resolveDueDate();

            $payment = $api->createPayment([
                'customer' => $customerId,
                'billingType' => $billingType,
                'value' => (float) $hash->data->amount_with_fee,
                'dueDate' => $dueDate,
                'description' => 'Fatura ' . ($this->invoice->number ?: '#' . $this->invoice->id),
                'externalReference' => $hash->hash,
                'postalService' => false,
            ]);
        } catch (\Throwable $e) {
            // The hash exists only to hold the charge reference; drop it so a
            // failed attempt never leaves an orphan row behind.
            $hash->delete();
            throw $e;
        }

        if (empty($payment['id'] ?? null)) {
            $hash->delete();

            throw new \RuntimeException('Asaas payment created without an id');
        }

        $data = (array) $hash->data;
        $data[$idKey] = $payment['id'] ?? null;
        $data[$statusKey] = $payment['status'] ?? 'PENDING';
        $dueKey = $billingType === 'PIX' ? 'asaas_pix_due_date' : 'asaas_due_date';
        $data[$dueKey] = $dueDate;

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

    private function safePix(AsaasApi $api, string $paymentId): array
    {
        try {
            return $api->getPixQrCode($paymentId);
        } catch (\Throwable $e) {
            return [];
        }
    }
}