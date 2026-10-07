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

use App\Jobs\WhatsApp\SendWhatsAppNotificationJob;
use App\Libraries\MultiDB;
use App\Models\Invoice;
use App\Services\WhatsApp\WhatsAppService;
use App\Utils\Traits\MakesDates;
use App\Utils\Traits\MakesReminders;
use Illuminate\Console\Command;

class SendWhatsAppRemindersCron extends Command
{
    use MakesReminders;
    use MakesDates;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ninja:send-whatsapp-reminders {--force : Forçar disparo para todas as faturas pendentes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispara lembretes automáticos de faturas pendentes via WhatsApp';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Iniciando processamento de lembretes automáticos via WhatsApp...');

        if (!config('ninja.db.multi_db_enabled')) {
            $this->processInvoices();
        } else {
            foreach (MultiDB::$dbs as $db) {
                MultiDB::setDB($db);
                $this->info("Processando banco de dados: {$db}");
                $this->processInvoices();
            }
        }

        $this->info('Processamento de lembretes via WhatsApp concluído!');
        return 0;
    }

    protected function processInvoices(): void
    {
        $force = (bool) $this->option('force');

        $query = Invoice::query()
            ->whereIn('status_id', [Invoice::STATUS_SENT, Invoice::STATUS_PARTIAL])
            ->where('is_deleted', 0)
            ->whereNull('deleted_at')
            ->where('balance', '>', 0)
            ->whereHas('client', function ($q) {
                $q->where('is_deleted', 0)->whereNull('deleted_at');
            })
            ->whereHas('company', function ($q) {
                $q->where('is_disabled', 0);
            })
            ->with(['client', 'company']);

        if (!$force) {
            $query->whereBetween('next_send_date', [now()->subMonth()->startOfDay(), now()]);
        }

        $count = 0;
        $query->cursor()->each(function (Invoice $invoice) use ($force, &$count) {
            if (!$invoice->isPayable()) {
                return;
            }

            $template = $invoice->calculateTemplate('invoice');
            if ($force && !in_array($template, ['reminder1', 'reminder2', 'reminder3', 'reminder_endless', 'endless_reminder'])) {
                $template = 'reminder1';
            }

            if (!in_array($template, ['reminder1', 'reminder2', 'reminder3', 'reminder_endless', 'endless_reminder'])) {
                return;
            }

            $service = new WhatsAppService($invoice->company);
            if (!$service->isConfigured()) {
                return;
            }

            $phone = $service->getRecipientPhone($invoice);
            if (!$phone) {
                $this->line("Fatura #{$invoice->number}: Cliente sem telefone de WhatsApp cadastrado. Ignorando.");
                return;
            }

            $this->line("Disparando lembrete ({$template}) para fatura #{$invoice->number} (Destinatário: {$phone})...");
            SendWhatsAppNotificationJob::dispatch(
                $invoice->withoutRelations(),
                $invoice->company->db,
                $template
            );

            $count++;
        });

        $this->info("Total de lembretes via WhatsApp despachados: {$count}");
    }
}
