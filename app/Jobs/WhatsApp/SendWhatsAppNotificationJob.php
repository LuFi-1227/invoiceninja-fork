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

namespace App\Jobs\WhatsApp;

use App\Libraries\MultiDB;
use App\Models\Invoice;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SendWhatsAppNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $tries = 2;
    public $timeout = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Invoice $invoice,
        public string $db,
        public ?string $reminderTemplate = 'invoice'
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (config('ninja.db.multi_db_enabled')) {
            MultiDB::setDB($this->db);
        }

        $company = $this->invoice->company;
        if (!$company || $company->is_disabled) {
            return;
        }

        // Prevent duplicate WhatsApp reminders on the same day for the same invoice & template
        $templateKey = $this->reminderTemplate ?: 'invoice';
        $cacheKey = "wa_sent_{$this->invoice->id}_{$templateKey}_" . now()->format('Ymd');
        if (Cache::has($cacheKey)) {
            Log::info("WhatsApp notification already sent today for invoice {$this->invoice->number} ({$templateKey}). Skipping.");
            return;
        }

        $service = new WhatsAppService($company);
        if (!$service->isConfigured()) {
            return;
        }

        $config = $service->getConfig();
        $isReminder = in_array($templateKey, ['reminder1', 'reminder2', 'reminder3', 'reminder_endless', 'endless_reminder']);

        // Check company settings for auto-dispatch
        if ($isReminder) {
            $autoSendReminders = $config['auto_send_reminders'] ?? true;
            if (!$autoSendReminders) {
                return;
            }
        } else {
            $autoSendInvoice = $config['auto_send_on_create'] ?? false;
            if (!$autoSendInvoice) {
                return;
            }
        }

        $result = $service->sendInvoiceReminder($this->invoice, $templateKey);

        if ($result['success'] ?? false) {
            Cache::put($cacheKey, true, now()->addHours(20));
            Log::info("WhatsApp notification dispatched successfully for invoice {$this->invoice->number} ({$templateKey}).");
        } else {
            Log::warning("WhatsApp notification failed for invoice {$this->invoice->number} ({$templateKey}): " . ($result['error'] ?? ''));
        }
    }
}
