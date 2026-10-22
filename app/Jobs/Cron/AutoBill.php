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

namespace App\Jobs\Cron;

use App\Jobs\Entity\EmailEntity;
use App\Libraries\MultiDB;
use App\Models\Invoice;
use App\Models\Webhook;
use App\Utils\Ninja;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;

class AutoBill implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $tries = 1;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(public int $invoice_id, public ?string $db, public bool $send_email_on_failure = false) {}

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        set_time_limit(0);

        if ($this->db) {
            MultiDB::setDb($this->db);
        }

        $invoice = Invoice::withTrashed()->findorFail($this->invoice_id);

        try {

            nlog("autobill {$this->invoice_id}");


                App::setLocale($invoice->client->locale());
                $t = app('translator');
                $t->replace(Ninja::transformTranslations($invoice->client->getMergedSettings()));
                $invoice->service()->autoBill();

        } catch (\Exception $e) {
            nlog("Failed to capture payment for {$this->invoice_id} ->" . $e->getMessage());

            if ($this->send_email_on_failure) {
                $user = $invoice->assigned_user
                    ?: $invoice->user
                    ?: ($invoice->user_id ? \App\Models\User::find($invoice->user_id) : null)
                    ?: ($invoice->company ? $invoice->company->owner() : null);

                $client = $invoice->client;

                $contactMethod = ($client && $client->hasContactMethod())
                    ? $client->contact_method
                    : ($user ? ($user->contact_method ?: 'whatsapp_and_email') : 'whatsapp_and_email');
                $method = strtolower(trim((string) $contactMethod));
                $shouldSendEmail = str_contains($method, 'email') || $method === 'both' || $method === 'all' || empty($method);
                $shouldSendWhatsApp = str_contains($method, 'whatsapp') || $method === 'both' || $method === 'all';

                if ($shouldSendEmail) {
                    $invoice->invitations->each(function ($invitation) use ($invoice) {

                    //2025-04-06 additional conditional check to prevent duplicate emails from being sent.
                    if (!$invitation->contact->trashed() && strlen($invitation->contact->email) >= 1 && $invoice->client->getSetting('auto_email_invoice') && !$invitation->contact->is_locked && $invoice->client->getSetting('client_online_payment_notification')) {
                        try {
                            EmailEntity::dispatch($invitation->withoutRelations(), $invoice->company->db)->delay(rand(1, 2));

                            $invoice->entityEmailEvent($invitation, 'invoice', 'email_template_invoice');

                        } catch (\Exception $e) {
                            nlog($e->getMessage());
                        }

                        nlog("Firing email for invoice {$invoice->number} which failed to capture payment");
                    }
                });
                }

                if ($shouldSendWhatsApp && $invoice->client->getSetting('client_online_payment_notification')) {
                    try {
                        $service = new \App\Services\WhatsApp\WhatsAppService($invoice->company);
                        if ($service->isConfigured()) {
                            $phone = $service->getRecipientPhone($invoice);
                            if ($phone) {
                                $service->sendInvoice($invoice);
                                nlog("Firing WhatsApp for invoice {$invoice->number} which failed to capture payment");
                            }
                        }
                    } catch (\Throwable $e) {
                        nlog("Exception while sending WhatsApp on autobill failure: " . $e->getMessage());
                    }
                }

                $invoice->sendEvent(Webhook::EVENT_SENT_INVOICE, "client");

            }

        }
    }
}
