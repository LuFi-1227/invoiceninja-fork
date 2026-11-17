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

use App\Factory\ExpenseFactory;
use App\Factory\VendorFactory;
use App\Models\Company;
use App\Models\CompanyGateway;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentType;
use App\Models\User;
use App\Models\Vendor;
use App\PaymentDrivers\Asaas\AsaasApi;
use App\Utils\Traits\GeneratesCounter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Synchronizes Asaas corporate debit card transactions directly into
 * GestoreMio / Invoice Ninja expenses so they appear in financial reports.
 */
class AsaasCardExpenseService
{
    use GeneratesCounter;

    /** Gateway key for Asaas */
    public const GATEWAY_KEY = '07e4d88e0bbf4ad1b7dfeb21434c76b7';

    /** Card transaction types */
    public const CARD_TYPES = [
        'ASAAS_CARD_TRANSACTION',
        'ASAAS_CARD_TRANSACTION_FEE',
        'ASAAS_CARD_RECHARGE_FEE',
    ];

    /** Fee types (taxas e tarifas internas cobradas pelo Asaas) */
    public const FEE_TYPES = [
        'PAYMENT_FEE',                        // Tarifa sobre cobrança recebida (Boleto, Pix, Cartão - ex: 1,99)
        'PAYMENT_MESSAGING_NOTIFICATION_FEE', // Tarifa de envio de mensagem de cobrança
        'PAYMENT_SMS_NOTIFICATION_FEE',       // Tarifa de envio de notificação por SMS
        'INSTANT_TEXT_MESSAGE_FEE',           // Tarifa de envio de notificação por WhatsApp
        'PHONE_CALL_NOTIFICATION_FEE',        // Tarifa de chamada de voz
        'POSTAL_SERVICE_FEE',                 // Tarifa de envio pelos Correios
        'TRANSFER_FEE',                       // Tarifa de transferência Pix / TED
        'BILL_PAYMENT_FEE',                   // Tarifa de pagamento de contas
        'INVOICE_FEE',                        // Tarifa de emissão de NF-e / NFS-e
        'PAYMENT_DUNNING_REQUEST_FEE',        // Tarifa de negativação Serasa
        'PAYMENT_DUNNING_RECEIVED_FEE',       // Tarifa de recuperação Serasa
        'MONTHLY_FEE',                        // Mensalidade da conta / plano Asaas
        'ASAAS_DEBIT_CARD_REQUEST_FEE',       // Tarifa de solicitação de cartão de débito
        'ASAAS_PREPAID_CARD_REQUEST_FEE',     // Tarifa de solicitação de cartão pré-pago
    ];

    /** All default expense types synchronized */
    public const DEFAULT_EXPENSE_TYPES = [
        'ASAAS_CARD_TRANSACTION',
        'ASAAS_CARD_TRANSACTION_FEE',
        'ASAAS_CARD_RECHARGE_FEE',
        'PAYMENT_FEE',
        'PAYMENT_MESSAGING_NOTIFICATION_FEE',
        'PAYMENT_SMS_NOTIFICATION_FEE',
        'INSTANT_TEXT_MESSAGE_FEE',
        'PHONE_CALL_NOTIFICATION_FEE',
        'POSTAL_SERVICE_FEE',
        'TRANSFER_FEE',
        'BILL_PAYMENT_FEE',
        'INVOICE_FEE',
        'PAYMENT_DUNNING_REQUEST_FEE',
        'PAYMENT_DUNNING_RECEIVED_FEE',
        'MONTHLY_FEE',
        'ASAAS_DEBIT_CARD_REQUEST_FEE',
        'ASAAS_PREPAID_CARD_REQUEST_FEE',
    ];

    /** Backwards-compatibility alias for DEFAULT_EXPENSE_TYPES */
    public const DEFAULT_CARD_TYPES = self::DEFAULT_EXPENSE_TYPES;

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
     * Synchronize card expenses for the company.
     *
     * @param array $options [
     *   'days' => 30,
     *   'start_date' => 'YYYY-MM-DD',
     *   'finish_date' => 'YYYY-MM-DD',
     *   'all_time' => false,
     *   'types' => ['ASAAS_CARD_TRANSACTION', ...],
     *   'category_name' => 'Cartão Asaas',
     *   'auto_create_vendor' => true,
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

        $days = (int) ($options['days'] ?? config('ninja.asaas.card_sync_days', 30));
        $allTime = filter_var($options['all_time'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $startDate = $options['start_date'] ?? ($allTime ? null : Carbon::now()->subDays($days)->format('Y-m-d'));
        $finishDate = $options['finish_date'] ?? ($allTime ? null : Carbon::now()->format('Y-m-d'));
        $types = $options['types'] ?? self::DEFAULT_EXPENSE_TYPES;
        $categoryName = (string) ($options['category_name'] ?? 'Cartão Asaas');
        $feeCategoryName = (string) ($options['fee_category_name'] ?? 'Taxas Asaas');
        $autoCreateVendor = filter_var($options['auto_create_vendor'] ?? true, FILTER_VALIDATE_BOOLEAN);

        $category = null;
        if (! empty($categoryName)) {
            $category = ExpenseCategory::query()
                ->where('company_id', $this->company->id)
                ->where('name', $categoryName)
                ->first();

            if (! $category) {
                $category = new ExpenseCategory();
                $category->company_id = $this->company->id;
                $category->user_id = $user->id;
                $category->name = $categoryName;
                $category->color = '#005b60';
                $category->is_deleted = false;
                $category->saveQuietly();
            }
        }

        $feeCategory = null;
        if (! empty($feeCategoryName)) {
            $feeCategory = ExpenseCategory::query()
                ->where('company_id', $this->company->id)
                ->where('name', $feeCategoryName)
                ->first();

            if (! $feeCategory) {
                $feeCategory = new ExpenseCategory();
                $feeCategory->company_id = $this->company->id;
                $feeCategory->user_id = $user->id;
                $feeCategory->name = $feeCategoryName;
                $feeCategory->color = '#e05638';
                $feeCategory->is_deleted = false;
                $feeCategory->saveQuietly();
            }
        }

        $currencyId = (int) ($this->company->getSetting('currency_id') ?: 20); // 20 = BRL

        $totalScanned = 0;
        $created = 0;
        $createdCard = 0;
        $createdFees = 0;
        $skipped = 0;
        $errors = [];
        $offset = 0;
        $limit = 100;

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
                Log::error("AsaasCardExpenseService error on offset {$offset}: " . $e->getMessage());
                $errors[] = $e->getMessage();
                break;
            }

            $items = $response['data'] ?? [];
            if (empty($items)) {
                break;
            }

            foreach ($items as $item) {
                $totalScanned++;
                $itemType = (string) ($item['type'] ?? '');

                $isFee = in_array($itemType, self::FEE_TYPES, true) || str_ends_with($itemType, '_FEE');

                $isAllowed = in_array($itemType, $types, true)
                    || ($types === self::DEFAULT_EXPENSE_TYPES && $isFee);

                if (! $isAllowed) {
                    continue;
                }

                $txId = (string) ($item['id'] ?? '');
                if (empty($txId)) {
                    continue;
                }

                $rawValue = (float) ($item['value'] ?? 0);
                // In Asaas, debit card expenses and fees are negative (outflow)
                if ($rawValue == 0) {
                    continue;
                }

                // Check if already synchronized
                $exists = Expense::query()
                    ->where('company_id', $this->company->id)
                    ->where('transaction_reference', $txId)
                    ->exists();

                if ($exists) {
                    $skipped++;
                    continue;
                }

                $amount = abs($rawValue);
                $date = ! empty($item['date']) ? $item['date'] : Carbon::now()->format('Y-m-d');
                $description = trim((string) ($item['description'] ?? ''));
                $paymentId = ! empty($item['paymentId']) ? (string) $item['paymentId'] : null;

                $vendorId = null;
                if ($autoCreateVendor) {
                    $vendorName = $this->extractVendorName($description, $itemType);
                    if (! empty($vendorName)) {
                        $vendor = Vendor::query()
                            ->where('company_id', $this->company->id)
                            ->where('name', $vendorName)
                            ->first();

                        if (! $vendor) {
                            $vendor = VendorFactory::create($this->company->id, $user->id);
                            $vendor->name = $vendorName;
                            $vendor->saveQuietly();
                        }

                        $vendorId = $vendor->id;
                    }
                }

                // Associate client / invoice if a corresponding Ninja Payment exists
                $clientId = null;
                $invoiceId = null;
                if ($paymentId) {
                    $matchedPayment = \App\Models\Payment::query()
                        ->where('company_id', $this->company->id)
                        ->where('transaction_reference', $paymentId)
                        ->first();

                    if ($matchedPayment) {
                        $clientId = $matchedPayment->client_id;
                        $firstInvoice = $matchedPayment->invoices()->first();
                        if ($firstInvoice) {
                            $invoiceId = $firstInvoice->id;
                        }
                    }
                }

                // Build clean public notes
                if (! empty($description)) {
                    $publicNotes = $description;
                } else {
                    $publicNotes = match ($itemType) {
                        'PAYMENT_FEE' => 'Taxa sobre cobrança recebida (Asaas)',
                        'PAYMENT_MESSAGING_NOTIFICATION_FEE' => 'Taxa de notificação de cobrança (Asaas)',
                        'PAYMENT_SMS_NOTIFICATION_FEE' => 'Taxa de envio de SMS (Asaas)',
                        'INSTANT_TEXT_MESSAGE_FEE' => 'Taxa de envio de WhatsApp (Asaas)',
                        'PHONE_CALL_NOTIFICATION_FEE' => 'Taxa de notificação por voz (Asaas)',
                        'POSTAL_SERVICE_FEE' => 'Taxa de envio Correios (Asaas)',
                        'TRANSFER_FEE' => 'Taxa de transferência (Asaas)',
                        'BILL_PAYMENT_FEE' => 'Taxa de pagamento de contas (Asaas)',
                        'INVOICE_FEE' => 'Taxa de emissão de NF-e (Asaas)',
                        'ASAAS_CARD_TRANSACTION_FEE' => 'Taxa de transação de cartão (Asaas)',
                        'ASAAS_CARD_RECHARGE_FEE' => 'Taxa de recarga de cartão (Asaas)',
                        'ASAAS_DEBIT_CARD_REQUEST_FEE' => 'Taxa de emissão de cartão de débito (Asaas)',
                        'ASAAS_PREPAID_CARD_REQUEST_FEE' => 'Taxa de emissão de cartão pré-pago (Asaas)',
                        'PAYMENT_DUNNING_REQUEST_FEE' => 'Taxa de negativação Serasa (Asaas)',
                        'PAYMENT_DUNNING_RECEIVED_FEE' => 'Taxa de recuperação Serasa (Asaas)',
                        'MONTHLY_FEE' => 'Mensalidade da conta Asaas',
                        default => $isFee ? 'Tarifa interna Asaas' : 'Compra Cartão Débito Asaas',
                    };
                }

                if ($paymentId && ! str_contains($publicNotes, $paymentId)) {
                    $publicNotes .= " ({$paymentId})";
                }

                $selectedCategory = $isFee ? $feeCategory : $category;

                try {
                    $expense = ExpenseFactory::create($this->company->id, $user->id);
                    $expense->amount = $amount;
                    $expense->currency_id = $currencyId;
                    $expense->date = $date;
                    $expense->payment_date = $date;
                    $expense->payment_type_id = PaymentType::DEBIT; // 3 = Debit
                    $expense->category_id = $selectedCategory ? $selectedCategory->id : null;
                    $expense->vendor_id = $vendorId;
                    $expense->client_id = $clientId;
                    $expense->invoice_id = $invoiceId;
                    $expense->transaction_reference = $txId;
                    $expense->public_notes = $publicNotes;
                    $expense->private_notes = "Sincronizado via Asaas API (ID: {$txId}, Tipo: {$itemType}" . ($paymentId ? ", Cobrança: {$paymentId}" : '') . ")";
                    $expense->should_be_invoiced = false;
                    $expense->number = $this->getNextExpenseNumber($expense);
                    $expense->saveQuietly();

                    $created++;
                    if ($isFee) {
                        $createdFees++;
                    } else {
                        $createdCard++;
                    }
                    Log::info("AsaasCardExpenseService: Expense created for transaction {$txId} (R$ {$amount}, Type: {$itemType})");
                } catch (\Throwable $e) {
                    Log::error("AsaasCardExpenseService failed creating expense for {$txId}: " . $e->getMessage());
                    $errors[] = "Falha ao criar despesa para transação {$txId}: " . $e->getMessage();
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
            'created_card' => $createdCard,
            'created_fees' => $createdFees,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Extracts a clean vendor / establishment name from the Asaas transaction description.
     */
    private function extractVendorName(string $description, string $type = ''): string
    {
        if (in_array($type, self::FEE_TYPES, true) || $type === 'ASAAS_CARD_TRANSACTION_FEE' || $type === 'ASAAS_CARD_RECHARGE_FEE') {
            return 'Asaas';
        }

        $desc = trim($description);
        if (empty($desc)) {
            return 'Cartão Asaas';
        }

        // Patterns:
        // "Compra com cartão - NOME ESTABELECIMENTO"
        // "Compra no débito - NOME ESTABELECIMENTO"
        // "ASAAS CARD - NOME ESTABELECIMENTO"
        if (preg_match('/^(?:compra\s+(?:com\s+cart[aã]o|no\s+d[eé]bito|com\s+o\s+cart[aã]o)|transa[cç][aã]o\s+com\s+cart[aã]o|asaas\s+card)\s*[-:]\s*(.+)$/iu', $desc, $matches)) {
            return trim($matches[1]);
        }

        if (str_contains($desc, ' - ')) {
            $parts = explode(' - ', $desc, 2);
            if (! empty(trim($parts[1]))) {
                return trim($parts[1]);
            }
        }

        return $desc;
    }
}
