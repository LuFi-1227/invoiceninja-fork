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

namespace App\PaymentDrivers\Asaas;

use App\Exceptions\PaymentFailed;
use App\Http\Requests\ClientPortal\Payments\PaymentResponseRequest;
use App\Jobs\Util\SystemLogger;
use App\Models\PaymentHash;
use App\Models\SystemLog;
use App\PaymentDrivers\AsaasPaymentDriver;
use App\PaymentDrivers\Common\LivewireMethodInterface;
use App\PaymentDrivers\Common\MethodInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class BankTransfer implements MethodInterface, LivewireMethodInterface
{
    public function __construct(protected AsaasPaymentDriver $driver)
    {
        $this->driver->init();
    }

    public function authorizeView(array $data)
    {
        // Asaas Boleto/Pix does not support pre-authorization
    }

    public function authorizeResponse(Request $request)
    {
        // Asaas Boleto/Pix does not support pre-authorization
    }

    public function paymentView(array $data)
    {
        $data = $this->paymentData($data);

        return render('gateways.asaas.pay', $data);
    }

    public function paymentResponse(PaymentResponseRequest $request)
    {
        $paymentHash = PaymentHash::where('hash', $request->payment_hash)->first();

        if (!$paymentHash) {
            throw new PaymentFailed(ctrans('texts.payment_hash_not_found') ?: 'Payment hash not found.');
        }

        $data = (array) $paymentHash->data;
        $status = $data['asaas_status'] ?? 'PENDING';

        if (in_array($status, ['RECEIVED', 'CONFIRMED']) && isset($data['asaas_payment_record_id'])) {
            return redirect()->route('client.payments.show', [
                'payment' => $data['asaas_payment_record_id'],
            ]);
        }

        throw new PaymentFailed(ctrans('texts.payment_was_not_completed') ?: 'Payment was not confirmed yet.');
    }

    /**
     * @inheritDoc
     */
    public function livewirePaymentView(array $data): string
    {
        return 'gateways.asaas.pay_livewire';
    }

    /**
     * @inheritDoc
     */
    public function paymentData(array $data): array
    {
        $data['gateway'] = $this->driver;
        $data['amount'] = $data['total']['amount_with_fee'] ?? ($this->driver->payment_hash->fee_total + array_sum(array_column($this->driver->payment_hash->invoices(), 'amount')));
        $data['currency'] = $this->driver->client->getCurrencyCode();

        try {
            $api = $this->driver->getApi();
            $client = $this->driver->client;
            $invoices = $this->driver->payment_hash->invoices();
            $firstInvoice = collect($invoices)->first();

            // Calculate due date (ensure not in the past)
            $dueDate = date('Y-m-d', strtotime('+3 days'));
            if ($firstInvoice && !empty($firstInvoice->due_date)) {
                $invDue = Carbon::parse($firstInvoice->due_date)->format('Y-m-d');
                if ($invDue >= date('Y-m-d')) {
                    $dueDate = $invDue;
                }
            }

            // Create or sync customer in Asaas
            $customerId = $api->createOrUpdateCustomer($client);

            $hashData = (array) $this->driver->payment_hash->data;
            $paymentId = $hashData['asaas_payment_id'] ?? null;
            $asaasPayment = null;

            if ($paymentId) {
                try {
                    $asaasPayment = $api->getPayment($paymentId);
                    // Check if already cancelled or expired
                    if (in_array($asaasPayment['status'] ?? '', ['OVERDUE', 'CANCELLED', 'REFUNDED'])) {
                        $paymentId = null;
                        $asaasPayment = null;
                    }
                } catch (\Throwable $e) {
                    $paymentId = null;
                }
            }

            if (!$paymentId) {
                $invoiceNumber = $firstInvoice ? ($firstInvoice->number ?? $firstInvoice->invoice_number ?? '') : '';
                $description = 'Fatura ' . ($invoiceNumber ? '#' . $invoiceNumber : '#' . $this->driver->payment_hash->hash);

                $paymentPayload = [
                    'customer' => $customerId,
                    'billingType' => 'BOLETO', // Generates Boleto with embedded Pix
                    'value' => (float) $data['amount'],
                    'dueDate' => $dueDate,
                    'description' => $description,
                    'externalReference' => $this->driver->payment_hash->hash,
                    'postalService' => false,
                ];

                $asaasPayment = $api->createPayment($paymentPayload);
                $paymentId = $asaasPayment['id'];
            }

            // Fetch Pix QR code and identification field
            $pixData = $api->getPixQrCode($paymentId);
            $boletoData = $api->getIdentificationField($paymentId);

            $pixPayload = $pixData['payload'] ?? '';
            $pixQrImage = $pixData['encodedImage'] ?? '';
            $linhaDigitavel = $boletoData['identificationField'] ?? '';
            $barcode = $boletoData['barCode'] ?? '';
            $bankSlipUrl = $asaasPayment['bankSlipUrl'] ?? '';
            $invoiceUrl = $asaasPayment['invoiceUrl'] ?? '';

            // Store in payment_hash for polling and webhook handling
            $hashData['asaas_payment_id'] = $paymentId;
            $hashData['asaas_status'] = $asaasPayment['status'] ?? 'PENDING';
            $hashData['asaas_pix_payload'] = $pixPayload;
            $hashData['asaas_pix_image'] = $pixQrImage;
            $hashData['asaas_linha_digitavel'] = $linhaDigitavel;
            $hashData['asaas_bank_slip_url'] = $bankSlipUrl;
            $hashData['asaas_invoice_url'] = $invoiceUrl;
            $hashData['asaas_due_date'] = $dueDate;

            $this->driver->payment_hash->data = $hashData;
            $this->driver->payment_hash->save();

            $data['payment_id'] = $paymentId;
            $data['pix_qr_image'] = $pixQrImage;
            $data['pix_payload'] = $pixPayload;
            $data['linha_digitavel'] = $linhaDigitavel;
            $data['barcode'] = $barcode;
            $data['bank_slip_url'] = $bankSlipUrl;
            $data['invoice_url'] = $invoiceUrl;
            $data['due_date'] = $dueDate;
            $data['company_name'] = $client->company->present()->name();
            $data['company_logo'] = $client->company->present()->logo();

        } catch (\Throwable $e) {
            SystemLogger::dispatch(
                ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString(), 'context' => 'Asaas:paymentData'],
                SystemLog::CATEGORY_GATEWAY_RESPONSE,
                SystemLog::EVENT_GATEWAY_FAILURE,
                SystemLog::TYPE_ASAAS,
                $this->driver->client,
                $this->driver->client->company,
            );

            throw new PaymentFailed('Asaas: ' . $e->getMessage());
        }

        return $data;
    }
}
