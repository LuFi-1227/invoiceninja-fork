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

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\CompanyGateway;
use App\Services\Asaas\AsaasCardExpenseService;
use App\Services\Asaas\AsaasIncomeService;
use Illuminate\Console\Command;

class AsaasSyncIncomes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'asaas:sync-incomes
                            {--company= : ID of the company to sync}
                            {--days=30 : Number of past days to sync}
                            {--start-date= : Custom start date (YYYY-MM-DD)}
                            {--finish-date= : Custom finish date (YYYY-MM-DD)}
                            {--all-time : Sync all transactions without date boundaries}
                            {--sync-expenses : Also sync debit card expenses in the same run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronize Asaas earnings / customer payments into GestoreMio payments';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting Asaas incomes synchronization...');

        $companyId = $this->option('company');
        $days = (int) $this->option('days');
        $startDate = $this->option('start-date');
        $finishDate = $this->option('finish-date');
        $allTime = (bool) $this->option('all-time');
        $syncExpenses = (bool) $this->option('sync-expenses');

        $options = [
            'days' => $days,
            'start_date' => $startDate,
            'finish_date' => $finishDate,
            'all_time' => $allTime,
        ];

        if ($companyId) {
            $companies = Company::query()->where('id', $companyId)->get();
            if ($companies->isEmpty()) {
                $this->error("Company with ID {$companyId} not found.");
                return Command::FAILURE;
            }
        } else {
            // Find all companies with an active Asaas gateway
            $companyIds = CompanyGateway::query()
                ->where('gateway_key', AsaasIncomeService::GATEWAY_KEY)
                ->where('is_deleted', 0)
                ->whereNull('deleted_at')
                ->pluck('company_id')
                ->unique();

            if ($companyIds->isEmpty()) {
                $this->warn('No companies with an active Asaas gateway found.');
                return Command::SUCCESS;
            }

            $companies = Company::query()->whereIn('id', $companyIds)->get();
        }

        $overallCreated = 0;
        $overallSkipped = 0;
        $overallScanned = 0;
        $hasErrors = false;

        foreach ($companies as $company) {
            $this->line("Processing Company: {$company->present()->name()} (ID: {$company->id})...");

            $service = AsaasIncomeService::forCompany($company);
            $result = $service->sync($options);

            if (! ($result['success'] ?? false)) {
                $this->error("Error for company {$company->id}: " . ($result['message'] ?? 'Unknown error'));
                if (! empty($result['errors'])) {
                    foreach ($result['errors'] as $err) {
                        $this->warn("  - {$err}");
                    }
                }
                $hasErrors = true;
            } else {
                $created = (int) ($result['created'] ?? 0);
                $skipped = (int) ($result['skipped'] ?? 0);
                $scanned = (int) ($result['total_scanned'] ?? 0);

                $overallCreated += $created;
                $overallSkipped += $skipped;
                $overallScanned += $scanned;

                $this->info("Company {$company->id} synced: {$created} payments created, {$skipped} skipped, {$scanned} scanned.");
            }

            if ($syncExpenses) {
                $this->line("Syncing expenses for Company ID: {$company->id}...");
                $expenseService = AsaasCardExpenseService::forCompany($company);
                $expResult = $expenseService->sync($options);
                if ($expResult['success'] ?? false) {
                    $this->info("Expenses: {$expResult['created']} created, {$expResult['skipped']} skipped.");
                } else {
                    $this->warn("Expense sync had warnings: " . ($expResult['message'] ?? ''));
                }
            }
        }

        $this->newLine();
        $this->info("Asaas incomes synchronization finished. Total created: {$overallCreated}, skipped: {$overallSkipped}, scanned: {$overallScanned}.");

        return $hasErrors ? Command::FAILURE : Command::SUCCESS;
    }
}
