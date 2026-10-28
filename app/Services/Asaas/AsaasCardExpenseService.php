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

    /** Transaction types considered card expenses by default */
    public const DEFAULT_CARD_TYPES = [
        'ASAAS_CARD_TRANSACTION',
        'ASAAS_CARD_TRANSACTION_FEE',
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
        $types = $options['types'] ?? self::DEFAULT_CARD_TYPES;
        $categoryName = (string) ($options['category_name'] ?? 'Cartão Asaas');
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

        $currencyId = (int) ($this->company->getSetting('currency_id') ?: 20); // 20 = BRL

        $totalScanned = 0;
        $created = 0;
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

                if (! in_array($itemType, $types, true)) {
                    continue;
                }

                $txId = (string) ($item['id'] ?? '');
                if (empty($txId)) {
                    continue;
                }

                $rawValue = (float) ($item['value'] ?? 0);
                // In Asaas, debit card expenses are negative (outflow)
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

                try {
                    $expense = ExpenseFactory::create($this->company->id, $user->id);
                    $expense->amount = $amount;
                    $expense->currency_id = $currencyId;
                    $expense->date = $date;
                    $expense->payment_date = $date;
                    $expense->payment_type_id = PaymentType::DEBIT; // 3 = Debit
                    $expense->category_id = $category ? $category->id : null;
                    $expense->vendor_id = $vendorId;
                    $expense->transaction_reference = $txId;
                    $expense->public_notes = $description ?: 'Compra Cartão Débito Asaas';
                    $expense->private_notes = "Sincronizado via Asaas API (ID: {$txId}, Tipo: {$itemType})";
                    $expense->should_be_invoiced = false;
                    $expense->number = $this->getNextExpenseNumber($expense);
                    $expense->saveQuietly();

                    $created++;
                    Log::info("AsaasCardExpenseService: Expense created for transaction {$txId} (R$ {$amount})");
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
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Extracts a clean vendor / establishment name from the Asaas transaction description.
     */
    private function extractVendorName(string $description, string $type = ''): string
    {
        if ($type === 'ASAAS_CARD_TRANSACTION_FEE') {
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
