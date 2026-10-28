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
use Illuminate\Console\Command;

class AsaasSyncCardExpenses extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'asaas:sync-card-expenses
                            {--company= : ID of the company to sync}
                            {--days=30 : Number of past days to sync}
                            {--start-date= : Custom start date (YYYY-MM-DD)}
                            {--finish-date= : Custom finish date (YYYY-MM-DD)}
                            {--all-time : Sync all transactions without date boundaries}
                            {--category=Cartão Asaas : Category name to group the expenses}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronize Asaas debit card expenses into GestoreMio expenses';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting Asaas card expenses synchronization...');

        $companyId = $this->option('company');
        $days = (int) $this->option('days');
        $startDate = $this->option('start-date');
        $finishDate = $this->option('finish-date');
        $allTime = (bool) $this->option('all-time');
        $categoryName = (string) $this->option('category');

        $options = [
            'days' => $days,
            'start_date' => $startDate,
            'finish_date' => $finishDate,
            'all_time' => $allTime,
            'category_name' => $categoryName,
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
                ->where('gateway_key', AsaasCardExpenseService::GATEWAY_KEY)
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

            $service = AsaasCardExpenseService::forCompany($company);
            $result = $service->sync($options);

            if (! ($result['success'] ?? false)) {
                $this->error("Error for company {$company->id}: " . ($result['message'] ?? 'Unknown error'));
                if (! empty($result['errors'])) {
                    foreach ($result['errors'] as $err) {
                        $this->error(" - {$err}");
                    }
                }
                $hasErrors = true;
                continue;
            }

            $created = $result['created'] ?? 0;
            $skipped = $result['skipped'] ?? 0;
            $scanned = $result['total_scanned'] ?? 0;

            $overallCreated += $created;
            $overallSkipped += $skipped;
            $overallScanned += $scanned;

            $this->info(" -> Scanned: {$scanned} | Created: {$created} | Skipped: {$skipped}");
        }

        $this->newLine();
        $this->info("Summary: {$overallScanned} scanned, {$overallCreated} created, {$overallSkipped} skipped.");

        return $hasErrors ? Command::FAILURE : Command::SUCCESS;
    }
}
