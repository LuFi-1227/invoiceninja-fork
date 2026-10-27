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

use App\Models\Client;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AsaasApi
{
    private const PRODUCTION_URL = 'https://api.asaas.com/v3';
    private const SANDBOX_URL = 'https://sandbox.asaas.com/api/v3';

    private string $apiKey;
    private bool $testMode;
    private ?string $webhookToken;
    private string $baseUrl;

    public function __construct(string $apiKey, bool $testMode = false, ?string $webhookToken = null)
    {
        $this->apiKey = trim($apiKey);
        $this->testMode = $testMode;
        $this->webhookToken = $webhookToken ? trim($webhookToken) : null;
        $this->baseUrl = $testMode ? self::SANDBOX_URL : self::PRODUCTION_URL;
    }

    /**
     * Test connection to Asaas API.
     */
    public function verifyConnection(): bool
    {
        try {
            $response = $this->client()->get($this->baseUrl . '/finance/balance');
            return $response->successful();
        } catch (\Throwable $e) {
            Log::error('Asaas verifyConnection error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Create or retrieve an existing customer in Asaas.
     */
    public function createOrUpdateCustomer(Client $client): string
    {
        // Clean CPF / CNPJ from encrypted custom_value1 or fallback
        $cpfCnpj = preg_replace('/\D/', '', $client->custom_value1 ?: '');
        if (empty($cpfCnpj)) {
            $cpfCnpj = preg_replace('/\D/', '', $client->vat_number ?: ($client->id_number ?: ''));
        }

        // Reuse the previously stored Asaas customer id when available so the
        // same customer is never duplicated on Asaas.
        if (!empty($client->asaas_id)) {
            return (string) $client->asaas_id;
        }

        // Check if customer already exists by externalReference or cpfCnpj
        if (!empty($client->hashed_id)) {
            $existing = $this->client()->get($this->baseUrl . '/customers', [
                'externalReference' => $client->hashed_id,
            ]);

            if ($existing->successful() && !empty($existing->json('data.0.id'))) {
                $customerId = (string) $existing->json('data.0.id');
                // Update customer info
                $this->updateCustomer($customerId, $client, $cpfCnpj);
                $this->persistCustomerId($client, $customerId);
                return $customerId;
            }
        }

        if (!empty($cpfCnpj)) {
            $existingByCpf = $this->client()->get($this->baseUrl . '/customers', [
                'cpfCnpj' => $cpfCnpj,
            ]);

            if ($existingByCpf->successful() && !empty($existingByCpf->json('data.0.id'))) {
                $customerId = (string) $existingByCpf->json('data.0.id');
                $this->updateCustomer($customerId, $client, $cpfCnpj);
                $this->persistCustomerId($client, $customerId);
                return $customerId;
            }
        }

        // Create new customer
        $payload = $this->buildCustomerPayload($client, $cpfCnpj);

        $response = $this->client()->post($this->baseUrl . '/customers', $payload);

        if (!$response->successful() || empty($response->json('id'))) {
            // Fallback with minimal required fields in case optional address fields were rejected by Asaas
            $contact = $client->contacts()->first();
            $minimalPayload = array_filter([
                'name' => $client->present()->name() ?: ($contact ? trim($contact->first_name . ' ' . $contact->last_name) : 'Cliente'),
                'cpfCnpj' => $cpfCnpj,
                'externalReference' => $client->hashed_id ?: null,
                'notificationDisabled' => true,
            ], fn($val) => $val !== '' && $val !== null);

            if ($minimalPayload !== $payload) {
                $retryResponse = $this->client()->post($this->baseUrl . '/customers', $minimalPayload);
                if ($retryResponse->successful() && !empty($retryResponse->json('id'))) {
                    $response = $retryResponse;
                }
            }
        }

        if (!$response->successful() || empty($response->json('id'))) {
            $errorMsg = $response->json('errors.0.description') ?? $response->body();
            throw new \Exception('Asaas customer creation failed: ' . $errorMsg);
        }

        $customerId = (string) $response->json('id');
        $this->persistCustomerId($client, $customerId);

        return $customerId;
    }

    /**
     * Build sanitized customer payload for Asaas API.
     * Note: Contact info (email, phone, mobilePhone) is omitted and notificationDisabled is set to true
     * to prevent Asaas from sending direct notifications/charges to the customer.
     */
    private function buildCustomerPayload(Client $client, string $cpfCnpj, bool $includeNotificationFlag = true): array
    {
        $contact = $client->contacts()->first();
        $postalCode = preg_replace('/\D/', '', $client->postal_code ?: '');
        if (strlen($postalCode) !== 8) {
            $postalCode = '';
        }

        $address = trim((string) ($client->address1 ?: ''));
        $addressNumber = '';
        $complement = trim((string) ($client->address2 ?: ''));

        if ($address !== '' && preg_match('/^(.+?),\s*(\d+[A-Za-z0-9\-\/]*)$/u', $address, $matches)) {
            $address = trim($matches[1]);
            $addressNumber = trim($matches[2]);
        } elseif ($complement !== '' && preg_match('/^\d+[A-Za-z0-9\-\/]*$/', $complement)) {
            $addressNumber = $complement;
            $complement = '';
        } elseif ($address !== '') {
            $addressNumber = 'S/N';
        }

        $payload = [
            'name' => $client->present()->name() ?: ($contact ? trim($contact->first_name . ' ' . $contact->last_name) : 'Cliente'),
            'cpfCnpj' => $cpfCnpj,
            'postalCode' => $postalCode,
            'address' => $address,
            'addressNumber' => $addressNumber,
            'complement' => $complement,
            'province' => $client->city ?: '',
            'externalReference' => $client->hashed_id,
        ];

        if ($includeNotificationFlag) {
            $payload['notificationDisabled'] = true;
        }

        return array_filter($payload, fn($val) => $val !== '' && $val !== null);
    }

    /**
     * Persist the Asaas customer id on the client (hidden field), reusing the
     * already loaded instance so subsequent charges skip the lookup/creation.
     */
    private function persistCustomerId(Client $client, string $customerId): void
    {
        if ($customerId === '' || (string) $client->asaas_id === $customerId) {
            return;
        }

        $client->asaas_id = $customerId;
        $client->saveQuietly();
    }

    /**
     * Update customer in Asaas.
     */
    public function updateCustomer(string $customerId, Client $client, string $cpfCnpj = ''): void
    {
        $payload = $this->buildCustomerPayload($client, $cpfCnpj, true);

        $this->client()->post($this->baseUrl . '/customers/' . $customerId, $payload);
    }

    /**
     * Create a payment (cobranca) in Asaas.
     */
    public function createPayment(array $paymentData): array
    {
        $response = $this->client()->post($this->baseUrl . '/payments', $paymentData);

        if (!$response->successful()) {
            $errorMsg = $response->json('errors.0.description') ?? $response->body();
            throw new \Exception('Asaas payment creation failed: ' . $errorMsg);
        }

        return (array) $response->json();
    }

    /**
     * Fetch Pix QR Code (base64 image and copy-paste string) for a payment.
     */
    public function getPixQrCode(string $paymentId): array
    {
        $response = $this->client()->get($this->baseUrl . '/payments/' . $paymentId . '/pixQrCode');

        if (!$response->successful()) {
            Log::warning("Asaas getPixQrCode failed for {$paymentId}: " . $response->body());
            return [];
        }

        return (array) $response->json();
    }

    /**
     * Fetch Boleto identification field (linha digitavel and barcode).
     */
    public function getIdentificationField(string $paymentId): array
    {
        $response = $this->client()->get($this->baseUrl . '/payments/' . $paymentId . '/identificationField');

        if (!$response->successful()) {
            Log::warning("Asaas getIdentificationField failed for {$paymentId}: " . $response->body());
            return [];
        }

        return (array) $response->json();
    }

    /**
     * Retrieve payment details from Asaas.
     */
    public function getPayment(string $paymentId): array
    {
        $response = $this->client()->get($this->baseUrl . '/payments/' . $paymentId);

        if (!$response->successful()) {
            throw new \Exception('Asaas getPayment failed: ' . $response->body());
        }

        return (array) $response->json();
    }

    /**
     * Find an active pending payment for a customer matching description or external reference.
     */
    public function findPendingPayment(string $customerId, ?string $description = null, ?string $externalReference = null): ?array
    {
        try {
            $query = ['customer' => $customerId, 'status' => 'PENDING'];
            if ($externalReference) {
                $query['externalReference'] = $externalReference;
            }
            $response = $this->client()->get($this->baseUrl . '/payments', $query);
            if ($response->successful()) {
                $items = $response->json('data') ?? [];
                foreach ($items as $item) {
                    if (empty($item['deleted']) && ($item['status'] ?? '') === 'PENDING') {
                        if ($description && ($item['description'] ?? '') === $description) {
                            return (array) $item;
                        }
                        if ($externalReference && ($item['externalReference'] ?? '') === $externalReference) {
                            return (array) $item;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Asaas findPendingPayment failed: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Delete / cancel a pending payment on Asaas.
     */
    public function deletePayment(string $paymentId): bool
    {
        try {
            $response = $this->client()->delete($this->baseUrl . '/payments/' . $paymentId);

            return $response->successful() && !empty($response->json('deleted'));
        } catch (\Throwable $e) {
            Log::warning('Asaas deletePayment failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Refund a payment in Asaas.
     */
    public function refundPayment(string $paymentId, float $amount, string $description = ''): array
    {
        $payload = [
            'value' => $amount,
            'description' => $description ?: 'Reembolso Invoice Ninja',
        ];

        $response = $this->client()->post($this->baseUrl . '/payments/' . $paymentId . '/refund', $payload);

        if (!$response->successful()) {
            $errorMsg = $response->json('errors.0.description') ?? $response->body();
            throw new \Exception('Asaas refund failed: ' . $errorMsg);
        }

        return (array) $response->json();
    }

    /**
     * Create / schedule a municipal service invoice (NFS-e) in Asaas.
     */
    public function createInvoice(array $invoiceData): array
    {
        $response = $this->client()->post($this->baseUrl . '/invoices', $invoiceData);

        if (!$response->successful()) {
            $errorMsg = $response->json('errors.0.description') ?? $response->body();
            throw new \Exception('Asaas invoice creation failed: ' . $errorMsg);
        }

        return (array) $response->json();
    }

    /**
     * Retrieve invoice (NFS-e) details from Asaas.
     */
    public function getInvoice(string $invoiceId): array
    {
        $response = $this->client()->get($this->baseUrl . '/invoices/' . $invoiceId);

        if (!$response->successful()) {
            throw new \Exception('Asaas getInvoice failed: ' . $response->body());
        }

        return (array) $response->json();
    }

    /**
     * Validate incoming webhook token header.
     */
    public function validateWebhookToken(?string $receivedToken): bool
    {
        if (empty($this->webhookToken)) {
            // If no webhook token is configured in company gateway settings, allow
            return true;
        }

        return hash_equals($this->webhookToken, trim($receivedToken ?? ''));
    }

    /**
     * Build HTTP client with Asaas headers.
     */
    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders([
            'access_token' => $this->apiKey,
            'Content-Type' => 'application/json',
            'User-Agent' => 'InvoiceNinja-Asaas/1.0',
        ])->timeout(30);
    }
}
