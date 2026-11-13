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

use App\Factory\ClientFactory;
use App\Factory\PaymentFactory;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyGateway;
use App\Models\GatewayType;
use App\Models\Payment;
use App\Models\PaymentHash;
use App\Models\PaymentType;
use App\Models\User;
use App\PaymentDrivers\Asaas\AsaasApi;
use App\Utils\Traits\GeneratesCounter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Synchronizes Asaas incoming transactions (gains / earnings / customer payments / pix)
 * directly into GestoreMio / Invoice Ninja payments so they appear in Livro Caixa and reports.
 */
class AsaasIncomeService
{
    use GeneratesCounter;

    /** Gateway key for Asaas */
    public const GATEWAY_KEY = '07e4d88e0bbf4ad1b7dfeb21434c76b7';

    /** Default incoming / credit transaction types */
    public const DEFAULT_INCOME_TYPES = [
        'PAYMENT_RECEIVED',
        'PIX_TRANSACTION_CREDIT',
        'TRANSFER',
        'RECEIVABLE_ANTICIPATION_GROSS_CREDIT',
        'PIX_TRANSACTION_DEBIT_REFUND',
        'REVERSAL',
        'FREE_PAYMENT_USE',
    ];

    private ?CompanyGateway $gateway = null;
    private ?AsaasApi $api = null;

    public function __construct(private Company $company) {}

    public static function forCompany(Company $company): self
    {
        return new self($company);
    }

    /**
     * Resolves the active Asaas CompanyGateway.
     */
    public function gateway(): ?CompanyGateway
    {
        if ($this->gateway === null) {
            $this->gateway = $this->company
                ->company_gateways()
                ->where('is_deleted', 0)
                ->whereNull('deleted_at')
                ->where('gateway_key', self::GATEWAY_KEY)
                ->orderByDesc('id')
                ->first();
        }

        return $this->gateway;
    }

    /**
     * Resolves the configured AsaasApi client.
     */
    public function api(): ?AsaasApi
    {
        if ($this->api === null) {
            $gateway = $this->gateway();
            if (! $gateway) {
                return null;
            }

            $apiKey = (string) $gateway->getConfigField('apiKey');
            $testMode = (bool) $gateway->getConfigField('testMode');
            $webhookToken = (string) $gateway->getConfigField('webhookToken');

            if (empty($apiKey)) {
                return null;
            }

            $this->api = new AsaasApi($apiKey, $testMode, $webhookToken);
        }

        return $this->api;
    }

    /**
     * Synchronize earnings / payments for the company.
     *
     * @param array $options [
     *   'days' => 30,
     *   'start_date' => 'YYYY-MM-DD',
     *   'finish_date' => 'YYYY-MM-DD',
     *   'all_time' => false,
     *   'types' => ['PAYMENT_RECEIVED', ...],
     *   'allow_any_positive' => true,
     *   'auto_create_client' => true,
     *   'default_client_name' => 'Asaas - Recebimentos',
     * ]
     * @return array
     */
    public function sync(array $options = []): array
    {
        $gateway = $this->gateway();
        if (! $gateway) {
            return [
                'success' => false,
                'message' => 'Nenhum gateway Asaas ativo encontrado para esta empresa.',
                'company_id' => $this->company->id,
                'total_scanned' => 0,
                'created' => 0,
                'skipped' => 0,
                'errors' => [],
            ];
        }

        $api = $this->api();
        if (! $api) {
            return [
                'success' => false,
                'message' => 'Credenciais da API do Asaas não configuradas ou inválidas.',
                'company_id' => $this->company->id,
                'total_scanned' => 0,
                'created' => 0,
                'skipped' => 0,
                'errors' => [],
            ];
        }

        $user = auth()->user()
            ?? $this->company->users()->first()
            ?? User::where('company_id', $this->company->id)->first();

        if (! $user) {
            return [
                'success' => false,
                'message' => 'Nenhum usuário encontrado para associar aos registros da empresa.',
                'company_id' => $this->company->id,
                'total_scanned' => 0,
                'created' => 0,
                'skipped' => 0,
                'errors' => [],
            ];
        }

        $days = (int) ($options['days'] ?? config('ninja.asaas.income_sync_days', 30));
        $allTime = filter_var($options['all_time'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $startDate = $options['start_date'] ?? ($allTime ? null : Carbon::now()->subDays($days)->format('Y-m-d'));
        $finishDate = $options['finish_date'] ?? ($allTime ? null : Carbon::now()->format('Y-m-d'));
        $types = $options['types'] ?? self::DEFAULT_INCOME_TYPES;
        $allowAnyPositive = filter_var($options['allow_any_positive'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $autoCreateClient = filter_var($options['auto_create_client'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $defaultClientName = (string) ($options['default_client_name'] ?? 'Asaas - Recebimentos');

        $currencyId = (int) ($this->company->getSetting('currency_id') ?: 20); // 20 = BRL

        $totalScanned = 0;
        $created = 0;
        $skipped = 0;
        $errors = [];
        $offset = 0;
        $limit = 100;

        // Cache dos clientes já consultados/criados durante esta execução para evitar queries repetidas
        $clientCache = [];

        do {
            $queryParams = [
                'offset' => $offset,
                'limit' => $limit,
            ];

            if (! $allTime) {
                if ($startDate) {
                    $queryParams['startDate'] = $startDate;
                }
                if ($finishDate) {
                    $queryParams['finishDate'] = $finishDate;
                }
            }

            try {
                $response = $api->getFinancialTransactions($queryParams);
            } catch (\Throwable $e) {
                Log::error("AsaasIncomeService error on offset {$offset}: " . $e->getMessage());
                $errors[] = $e->getMessage();
                break;
            }

            $items = $response['data'] ?? [];
            if (empty($items)) {
                break;
            }

            foreach ($items as $item) {
                $totalScanned++;
                $rawValue = (float) ($item['value'] ?? 0);

                // Ganhos/entradas possuem valor positivo no Asaas
                if ($rawValue <= 0) {
                    continue;
                }

                $itemType = (string) ($item['type'] ?? '');
                if (! $allowAnyPositive && ! in_array($itemType, $types, true)) {
                    continue;
                }

                $txId = (string) ($item['id'] ?? '');
                if (empty($txId)) {
                    continue;
                }

                $paymentId = ! empty($item['paymentId']) ? (string) $item['paymentId'] : null;
                $externalReference = ! empty($item['externalReference']) ? (string) $item['externalReference'] : null;

                // 1. Verificar idempotência pelo ID da transação financeira (ftn_...)
                $exists = Payment::query()
                    ->where('company_id', $this->company->id)
                    ->where('transaction_reference', $txId)
                    ->exists();

                if ($exists) {
                    $skipped++;
                    continue;
                }

                // 2. Verificar se já existe pagamento gravado pelo ID da cobrança (pay_...) via webhook
                if ($paymentId) {
                    $existsPaymentId = Payment::query()
                        ->where('company_id', $this->company->id)
                        ->where('transaction_reference', $paymentId)
                        ->exists();

                    if ($existsPaymentId) {
                        $skipped++;
                        continue;
                    }
                }

                // 3. Verificar por hash externo
                if ($externalReference) {
                    $hash = PaymentHash::where('hash', $externalReference)->first();
                    if ($hash && ! empty($hash->payment_id)) {
                        $skipped++;
                        continue;
                    }
                }

                $amount = abs($rawValue);
                $date = ! empty($item['date']) ? $item['date'] : Carbon::now()->format('Y-m-d');
                $description = trim((string) ($item['description'] ?? ''));

                // Resolver cliente
                $clientName = $this->extractClientName($description, $defaultClientName);
                $clientId = $clientCache[$clientName] ?? null;

                if (! $clientId) {
                    $client = Client::query()
                        ->where('company_id', $this->company->id)
                        ->where('name', $clientName)
                        ->first();

                    if (! $client && $autoCreateClient) {
                        try {
                            $client = ClientFactory::create($this->company->id, $user->id);
                            $client->name = $clientName;
                            $client->number = $this->getNextClientNumber($client);
                            $client->saveQuietly();
                        } catch (\Throwable $ce) {
                            Log::warning("AsaasIncomeService: could not create client '{$clientName}': " . $ce->getMessage());
                            // Fallback para o cliente padrão
                            if ($clientName !== $defaultClientName) {
                                $client = Client::query()
                                    ->where('company_id', $this->company->id)
                                    ->where('name', $defaultClientName)
                                    ->first();

                                if (! $client) {
                                    $client = ClientFactory::create($this->company->id, $user->id);
                                    $client->name = $defaultClientName;
                                    $client->number = $this->getNextClientNumber($client);
                                    $client->saveQuietly();
                                }
                            }
                        }
                    } elseif (! $client) {
                        // Se não auto-criar, buscar ou criar cliente padrão
                        $client = Client::query()
                            ->where('company_id', $this->company->id)
                            ->where('name', $defaultClientName)
                            ->first();

                        if (! $client) {
                            $client = ClientFactory::create($this->company->id, $user->id);
                            $client->name = $defaultClientName;
                            $client->number = $this->getNextClientNumber($client);
                            $client->saveQuietly();
                        }
                    }

                    if ($client) {
                        $clientId = $client->id;
                        $clientCache[$clientName] = $clientId;
                    }
                }

                if (! $clientId) {
                    Log::error("AsaasIncomeService: No valid client found for transaction {$txId}");
                    $errors[] = "Nenhum cliente disponível para a transação {$txId}";
                    continue;
                }

                $paymentTypeId = $this->resolvePaymentTypeId($description, $itemType);

                try {
                    $clientModel = Client::find($clientId);

                    $payment = PaymentFactory::create($this->company->id, $user->id, $clientId);
                    $payment->amount = $amount;
                    $payment->currency_id = $currencyId;
                    $payment->date = $date;
                    $payment->status_id = Payment::STATUS_COMPLETED;
                    $payment->type_id = $paymentTypeId;
                    $payment->gateway_type_id = GatewayType::BANK_TRANSFER;
                    $payment->company_gateway_id = $gateway->id;
                    $payment->transaction_reference = $txId;
                    $payment->private_notes = $description ?: "Recebimento Asaas ({$itemType})";
                    if ($paymentId) {
                        $payment->custom_value1 = $paymentId;
                    }
                    $payment->number = $this->getNextPaymentNumber($clientModel, $payment);
                    $payment->saveQuietly();

                    $created++;
                    Log::info("AsaasIncomeService: Payment created for transaction {$txId} (R$ {$amount})");
                } catch (\Throwable $e) {
                    Log::error("AsaasIncomeService failed creating payment for {$txId}: " . $e->getMessage());
                    $errors[] = "Falha ao criar recebimento para transação {$txId}: " . $e->getMessage();
                }
            }

            $hasMore = (bool) ($response['hasMore'] ?? false);
            $offset += count($items);

        } while ($hasMore && count($items) === $limit);

        return [
            'success' => empty($errors),
            'company_id' => $this->company->id,
            'start_date' => $startDate,
            'finish_date' => $finishDate,
            'total_scanned' => $totalScanned,
            'created' => $created,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Extracts a clean client name from the Asaas transaction description.
     */
    public function extractClientName(string $description, string $default = 'Asaas - Recebimentos'): string
    {
        $desc = trim($description);
        if (empty($desc)) {
            return $default;
        }

        // Padrão 1: "Cobrança recebida - fatura nr. 123456789 Nome do Cliente"
        if (preg_match('/fatura\s+(?:nr\.|nº|n°|número|numero)?\s*\d+\s+[-:]?\s*(.+)$/iu', $desc, $matches)) {
            $extracted = trim($matches[1]);
            if (! empty($extracted)) {
                return $extracted;
            }
        }

        // Padrão 2: "Cobrança recebida - Nome do Cliente"
        if (preg_match('/^(?:cobran[cç]a\s+recebida|recebimento)\s*[-:]\s*(.+)$/iu', $desc, $matches)) {
            $extracted = trim($matches[1]);
            if (! empty($extracted)) {
                return $extracted;
            }
        }

        // Padrão 3: "Transação via Pix recebida de Nome" / "via Pix de Nome" / "com chave de Nome"
        if (preg_match('/(?:recebid[ao]\s+de|via\s+pix\s+(?:recebida\s+)?de|com\s+chave\s+de)\s+([^\d\-\,\;\.]+)/iu', $desc, $matches)) {
            $extracted = trim($matches[1]);
            if (! empty($extracted)) {
                return $extracted;
            }
        }

        // Padrão 4: Separador " - Nome"
        if (str_contains($desc, ' - ')) {
            $parts = explode(' - ', $desc);
            $lastPart = trim(end($parts));
            if (! empty($lastPart) && ! preg_match('/^\d+$/', $lastPart)) {
                return $lastPart;
            }
        }

        return $default;
    }

    /**
     * Resolves the PaymentType ID based on description and Asaas transaction type.
     */
    public function resolvePaymentTypeId(string $description, string $type = ''): int
    {
        $descLower = mb_strtolower($description);
        $typeLower = mb_strtolower($type);

        if (str_contains($descLower, 'cartão') || str_contains($descLower, 'cartao') || str_contains($typeLower, 'credit_card')) {
            return PaymentType::CREDIT_CARD_OTHER;
        }

        // Pix, Boleto, Transferência -> Bank Transfer (padrão Brasil)
        return PaymentType::BANK_TRANSFER;
    }
}
