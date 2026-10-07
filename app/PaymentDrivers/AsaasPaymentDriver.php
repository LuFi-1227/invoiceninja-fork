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

namespace App\PaymentDrivers;

use App\Http\Requests\Payments\PaymentNotificationWebhookRequest;
use App\Jobs\Util\SystemLogger;
use App\Models\GatewayType;
use App\Models\Payment;
use App\Models\PaymentHash;
use App\Models\PaymentType;
use App\Models\SystemLog;
use App\PaymentDrivers\Asaas\AsaasApi;
use App\PaymentDrivers\Asaas\BankTransfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AsaasPaymentDriver extends BaseDriver
{
    public $refundable = true;

    public $token_billing = false;

    public $can_authorise_credit_card = false;

    public $payment_method;

    public static $methods = [
        GatewayType::BANK_TRANSFER => BankTransfer::class,
    ];

    public const SYSTEM_LOG_TYPE = SystemLog::TYPE_ASAAS;

    private ?AsaasApi $api = null;

    public function gatewayTypes(): array
    {
        return [
            GatewayType::BANK_TRANSFER,
        ];
    }

    public function setPaymentMethod($payment_method_id)
    {
        $class = self::$methods[$payment_method_id] ?? BankTransfer::class;
        $this->payment_method = new $class($this);

        return $this;
    }

    /**
     * Mandatory fields required by Asaas for Brazilian Boleto and Pix payments.
     */
    public function getClientRequiredFields(): array
    {
        $fields = [];

        $cpfLabel = 'CPF / CNPJ';
        if ($this->client && $this->client->company) {
            $customFields = (object) ($this->client->company->custom_fields ?? []);
            if (!empty($customFields->client1)) {
                $cpfLabel = $customFields->client1;
            }
        }

        $fields[] = [
            'name' => 'client_custom_value1',
            'label' => $cpfLabel,
            'type' => 'text',
            'validation' => 'required',
        ];

        $fields[] = [
            'name' => 'contact_email',
            'label' => ctrans('texts.email'),
            'type' => 'text',
            'validation' => 'required,email:rfc',
        ];

        return $fields;
    }

    /**
     * Get initialized AsaasApi instance.
     */
    public function getApi(): AsaasApi
    {
        if ($this->api === null) {
            $apiKey = (string) $this->company_gateway->getConfigField('apiKey');
            $testMode = (bool) $this->company_gateway->getConfigField('testMode');
            $webhookToken = (string) $this->company_gateway->getConfigField('webhookToken');

            $this->api = new AsaasApi($apiKey, $testMode, $webhookToken);
        }

        return $this->api;
    }

    /**
     * Test connection to Asaas API for gateway setup verification.
     */
    public function auth(): string
    {
        return $this->getApi()->verifyConnection() ? 'ok' : 'error';
    }

    public function authorizeView(array $data)
    {
        // Not supported for Pix / Boleto
    }

    public function authorizeResponse($request)
    {
        // Not supported for Pix / Boleto
    }

    public function processPaymentView(array $data)
    {
        return $this->payment_method->paymentView($data);
    }

    public function processPaymentResponse($request)
    {
        return $this->payment_method->paymentResponse($request);
    }

    /**
     * Refund payment via Asaas API.
     */
    public function refund(Payment $payment, $amount, $return_client_response = false)
    {
        try {
            $transactionRef = $payment->transaction_reference;
            if (empty($transactionRef)) {
                throw new \Exception('No transaction_reference found on payment.');
            }

            $response = $this->getApi()->refundPayment($transactionRef, (float) $amount);

            SystemLogger::dispatch(
                ['payment_id' => $payment->id, 'amount' => $amount, 'response' => $response],
                SystemLog::CATEGORY_GATEWAY_RESPONSE,
                SystemLog::EVENT_GATEWAY_SUCCESS,
                SystemLog::TYPE_ASAAS,
                $payment->client,
                $payment->company,
            );

            return [
                'transaction_reference' => $transactionRef,
                'transaction_response' => json_encode($response),
                'success' => true,
                'description' => 'Refund processed via Asaas',
                'code' => 200,
                'amount' => (float) $amount,
            ];
        } catch (\Throwable $e) {
            SystemLogger::dispatch(
                ['payment_id' => $payment->id, 'amount' => $amount, 'error' => $e->getMessage()],
                SystemLog::CATEGORY_GATEWAY_RESPONSE,
                SystemLog::EVENT_GATEWAY_FAILURE,
                SystemLog::TYPE_ASAAS,
                $payment->client,
                $payment->company,
            );

            return [
                'transaction_reference' => $payment->transaction_reference,
                'transaction_response' => $e->getMessage(),
                'success' => false,
                'description' => $e->getMessage(),
                'code' => 400,
            ];
        }
    }

    /**
     * Process Webhooks from Asaas and browser status polling checks.
     */
    public function processWebhookRequest($request)
    {
        // 1. Handle browser live polling GET check
        if ($request->isMethod('GET') && $request->has('check_status')) {
            $hash = $request->input('payment_hash');
            $paymentHash = PaymentHash::where('hash', $hash)->first();

            if (!$paymentHash) {
                return response()->json(['status' => 'FAILED', 'message' => 'Payment hash not found'], 404);
            }

            $data = (array) $paymentHash->data;
            $status = $data['asaas_status'] ?? 'PENDING';

            $response = [
                'status' => $status,
                'payment_record_id' => $data['asaas_payment_record_id'] ?? null,
            ];

            return response()->json($response);
        }

        // 2. Validate webhook authorization token from Asaas header
        $receivedToken = $request->header('asaas-access-token') ?? $request->header('Asaas-Access-Token');
        if (!$this->getApi()->validateWebhookToken($receivedToken)) {
            Log::warning('Asaas webhook rejected: invalid asaas-access-token');
            return response()->json(['error' => 'Invalid webhook token'], 401);
        }

        $payload = $request->all();
        $event = $payload['event'] ?? '';
        $paymentData = $payload['payment'] ?? [];

        Log::info("Asaas webhook received: event={$event}, paymentId=" . ($paymentData['id'] ?? ''));

        if (empty($paymentData['id'])) {
            return response()->json(['error' => 'Missing payment data'], 400);
        }

        $transactionId = (string) $paymentData['id'];
        $externalReference = (string) ($paymentData['externalReference'] ?? '');

        // Locate payment hash by externalReference
        $paymentHash = null;
        if (!empty($externalReference)) {
            $paymentHash = PaymentHash::where('hash', $externalReference)->first();
        }

        // Fallback: search by stored asaas_payment_id in payment_hash data
        if (!$paymentHash) {
            $paymentHash = PaymentHash::where('data->asaas_payment_id', $transactionId)->first();
        }

        if (!$paymentHash) {
            Log::info("Asaas webhook: payment hash not found for externalReference={$externalReference}, transactionId={$transactionId}");
            return response()->json(['status' => 'ignored', 'message' => 'Payment hash not found'], 200);
        }

        $hashData = (array) $paymentHash->data;
        $hashData['asaas_status'] = $paymentData['status'] ?? $event;

        // Process successful payment confirmation
        if (in_array($event, ['PAYMENT_RECEIVED', 'PAYMENT_CONFIRMED'])) {
            $invoice = $paymentHash->fee_invoice;

            if ($invoice) {
                $this->client = $invoice->client;
                $this->setPaymentMethod(GatewayType::BANK_TRANSFER);
                $this->payment_hash = $paymentHash;

                // Idempotency: verify if payment was already recorded
                $existingPayment = Payment::where('transaction_reference', $transactionId)
                    ->where('company_id', $this->company_gateway->company_id)
                    ->first();

                if ($existingPayment) {
                    $hashData['asaas_payment_record_id'] = $existingPayment->hashed_id;
                    Log::info("Asaas webhook: duplicate confirmation for {$transactionId} - already recorded as payment {$existingPayment->id}");
                } else {
                    $billingType = $paymentData['billingType'] ?? 'BOLETO';
                    $paymentTypeName = $billingType === 'PIX' ? PaymentType::BANK_TRANSFER : PaymentType::BANK_TRANSFER;

                    $pRecord = [
                        'payment_method' => GatewayType::BANK_TRANSFER,
                        'payment_type' => $paymentTypeName,
                        'amount' => (float) ($paymentData['value'] ?? $paymentHash->data->amount_with_fee),
                        'gateway_type_id' => GatewayType::BANK_TRANSFER,
                        'transaction_reference' => $transactionId,
                    ];

                    $payment = $this->createPayment($pRecord, Payment::STATUS_COMPLETED);
                    $hashData['asaas_payment_record_id'] = $payment->hashed_id;
                    $hashData['asaas_status'] = 'CONFIRMED';

                    SystemLogger::dispatch(
                        ['event' => $event, 'payment' => $paymentData, 'ninja_payment_id' => $payment->id],
                        SystemLog::CATEGORY_GATEWAY_RESPONSE,
                        SystemLog::EVENT_GATEWAY_SUCCESS,
                        SystemLog::TYPE_ASAAS,
                        $this->client,
                        $this->client->company,
                    );

                    Log::info("Asaas payment recorded successfully: ID {$payment->id} for {$transactionId}");
                }
            }
        } elseif (in_array($event, ['PAYMENT_REFUNDED'])) {
            $hashData['asaas_status'] = 'REFUNDED';
            Log::info("Asaas payment {$transactionId} refunded");
        } elseif (in_array($event, ['PAYMENT_OVERDUE'])) {
            $hashData['asaas_status'] = 'OVERDUE';
            Log::info("Asaas payment {$transactionId} marked overdue");
        }

        $paymentHash->data = $hashData;
        $paymentHash->save();

        return response()->json(['status' => 'ok'], 200);
    }
}
