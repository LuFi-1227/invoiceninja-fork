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

namespace App\Services\WhatsApp;

use App\Jobs\Util\SystemLogger;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\SystemLog;
use App\Services\Asaas\AsaasBoletoService;
use App\Services\Invoice\GetInvoicePdf;
use App\Services\WhatsApp\Contracts\WhatsAppDriverInterface;
use App\Services\WhatsApp\Drivers\EvolutionApiDriver;
use App\Services\WhatsApp\Drivers\MetaApiDriver;
use App\Utils\Number;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    protected Company $company;
    protected ?WhatsAppDriverInterface $driver = null;
    protected array $config = [];

    public function __construct(Company $company)
    {
        $this->company = $company;
        $this->loadConfiguration();
    }

    /**
     * Load WhatsApp configuration from company settings or env fallback.
     */
    protected function loadConfiguration(): void
    {
        $companySettings = (array) ($this->company->settings->whatsapp ?? []);

        $provider = $companySettings['provider'] ?? config('ninja.whatsapp.provider', env('WHATSAPP_PROVIDER', 'evolution'));

        $this->config = [
            'provider' => $provider,
            'enabled' => (bool) ($companySettings['enabled'] ?? config('ninja.whatsapp.enabled', env('WHATSAPP_ENABLED', true))),
            'send_pdf' => (bool) ($companySettings['send_pdf'] ?? config('ninja.whatsapp.send_pdf', env('WHATSAPP_SEND_PDF', false))),
            'send_boleto' => (bool) ($companySettings['send_boleto'] ?? config('ninja.whatsapp.send_boleto', env('WHATSAPP_SEND_BOLETO', false))),
            'auto_send_on_create' => (bool) ($companySettings['auto_send_on_create'] ?? false),
            'auto_send_reminders' => (bool) ($companySettings['auto_send_reminders'] ?? config('ninja.whatsapp.auto_send_reminders', true)),
            'message_template' => $companySettings['message_template'] ?? null,
            'template_reminder1' => $companySettings['template_reminder1'] ?? null,
            'template_reminder2' => $companySettings['template_reminder2'] ?? null,
            'template_reminder3' => $companySettings['template_reminder3'] ?? null,
            'template_endless_reminder' => $companySettings['template_endless_reminder'] ?? null,

            // Evolution API fields
            'evolution_api_url' => $companySettings['evolution_api_url'] ?? config('ninja.whatsapp.evolution.api_url', env('EVOLUTION_API_URL', '')),
            'evolution_api_key' => $companySettings['evolution_api_key'] ?? config('ninja.whatsapp.evolution.api_key', env('EVOLUTION_API_KEY', '')),
            'evolution_instance' => $companySettings['evolution_instance'] ?? config('ninja.whatsapp.evolution.instance', env('EVOLUTION_INSTANCE', '')),

            // Meta Cloud API fields
            'meta_phone_number_id' => $companySettings['meta_phone_number_id'] ?? config('ninja.whatsapp.meta.phone_number_id', env('META_PHONE_NUMBER_ID', '')),
            'meta_access_token' => $companySettings['meta_access_token'] ?? config('ninja.whatsapp.meta.access_token', env('META_ACCESS_TOKEN', '')),
        ];

        if ($provider === 'meta') {
            if (!empty($this->config['meta_phone_number_id']) && !empty($this->config['meta_access_token'])) {
                $this->driver = new MetaApiDriver(
                    (string) $this->config['meta_phone_number_id'],
                    (string) $this->config['meta_access_token']
                );
            }
        } else {
            // Default: Evolution API
            if (!empty($this->config['evolution_api_url']) && !empty($this->config['evolution_api_key']) && !empty($this->config['evolution_instance'])) {
                $this->driver = new EvolutionApiDriver(
                    (string) $this->config['evolution_api_url'],
                    (string) $this->config['evolution_api_key'],
                    (string) $this->config['evolution_instance']
                );
            }
        }
    }

    /**
     * Check if WhatsApp service is configured and ready.
     */
    public function isConfigured(): bool
    {
        return $this->driver !== null && $this->config['enabled'];
    }

    /**
     * Get the active driver instance.
     */
    public function getDriver(): ?WhatsAppDriverInterface
    {
        return $this->driver;
    }

    /**
     * Get current sanitized configuration for display.
     */
    public function getConfig(): array
    {
        $safeConfig = $this->config;

        // Mask sensitive tokens
        if (!empty($safeConfig['evolution_api_key'])) {
            $safeConfig['evolution_api_key_masked'] = substr($safeConfig['evolution_api_key'], 0, 4) . '***' . substr($safeConfig['evolution_api_key'], -4);
        }
        if (!empty($safeConfig['meta_access_token'])) {
            $safeConfig['meta_access_token_masked'] = substr($safeConfig['meta_access_token'], 0, 4) . '***' . substr($safeConfig['meta_access_token'], -4);
        }

        return $safeConfig;
    }

    /**
     * Save configuration to company settings.
     */
    public function saveSettings(array $settings): void
    {
        $currentSettings = $this->company->settings ?? new \stdClass();
        $whatsappSettings = (array) ($currentSettings->whatsapp ?? []);

        foreach ($settings as $key => $value) {
            $whatsappSettings[$key] = $value;
        }

        $currentSettings->whatsapp = $whatsappSettings;
        $this->company->settings = $currentSettings;
        $this->company->save();

        $this->loadConfiguration();
    }

    /**
     * Normalize Brazilian phone number format.
     */
    public static function normalizePhoneNumber(?string $phone): ?string
    {
        if (empty($phone)) {
            return null;
        }

        // Keep only digits
        $digits = preg_replace('/\D/', '', $phone);

        if (empty($digits)) {
            return null;
        }

        // If local Brazilian number with DDD (10 or 11 digits), prepend Brazil DDI (55)
        if (strlen($digits) === 10 || strlen($digits) === 11) {
            return '55' . $digits;
        }

        // If already has 55 and standard size
        if (str_starts_with($digits, '55') && (strlen($digits) === 12 || strlen($digits) === 13)) {
            return $digits;
        }

        // Return digits as is for international numbers
        return $digits;
    }

    /**
     * Resolve recipient phone number from an invoice's client and contacts.
     */
    public function getRecipientPhone(Invoice $invoice): ?string
    {
        $client = $invoice->client;

        // 1. Client phone
        if (!empty($client->phone)) {
            $phone = self::normalizePhoneNumber($client->phone);
            if ($phone) {
                return $phone;
            }
        }

        // 2. Primary contact phone
        $primaryContact = $client->primary_contact()->first();
        if ($primaryContact && !empty($primaryContact->phone)) {
            $phone = self::normalizePhoneNumber($primaryContact->phone);
            if ($phone) {
                return $phone;
            }
        }

        // 3. Any contact phone
        $firstContactWithPhone = $client->contacts()->whereNotNull('phone')->where('phone', '!=', '')->first();
        if ($firstContactWithPhone) {
            return self::normalizePhoneNumber($firstContactWithPhone->phone);
        }

        return null;
    }

    /**
     * Build invoice notification message text with placeholders replaced.
     */
    public function buildMessage(Invoice $invoice, ?string $customTemplate = null): string
    {
        $template = $customTemplate ?: ($this->config['message_template'] ?: null);

        if (!$template) {
            $template = "Olá, *:contact*!\n\n"
                      . "Sua fatura *:invoice* no valor de *:amount* foi emitida com vencimento para *:due_date*.\n\n"
                      . "Para visualizar os detalhes e pagar via *Pix ou Boleto*, acesse o link:\n"
                      . ":link\n\n"
                      . "Qualquer dúvida, estamos à disposição!\n"
                      . "*:company*";
        }

        $client = $invoice->client;
        $contact = $client->primary_contact()->first() ?: $client->contacts()->first();
        $contactName = $contact ? trim($contact->first_name . ' ' . $contact->last_name) : $client->present()->name();
        if (empty($contactName)) {
            $contactName = 'Cliente';
        }

        $invitation = $invoice->invitations()->first();
        if (!$invitation) {
            $invoice->service()->createInvitations()->save();
            $invoice->load('invitations');
            $invitation = $invoice->invitations()->first();
        }

        $link = $invitation ? $invitation->getLink() : '';
        $amountFormatted = Number::formatMoney($invoice->amount, $client);
        $dueDateFormatted = !empty($invoice->due_date) ? Carbon::parse($invoice->due_date)->format('d/m/Y') : 'À vista';

        $replacements = [
            ':contact' => $contactName,
            ':client' => $client->present()->name(),
            ':invoice' => $invoice->number ?: '#' . $invoice->id,
            ':amount' => $amountFormatted,
            ':due_date' => $dueDateFormatted,
            ':link' => $link,
            ':company' => $this->company->present()->name(),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    /**
     * Send invoice notification via WhatsApp.
     */
    public function sendInvoice(Invoice $invoice, ?string $customPhone = null, ?string $customMessage = null, ?bool $sendPdf = null, ?bool $sendBoleto = null): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'error' => 'WhatsApp não está configurado ou ativado para esta empresa.',
            ];
        }

        $recipientPhone = $customPhone ? self::normalizePhoneNumber($customPhone) : $this->getRecipientPhone($invoice);

        if (empty($recipientPhone)) {
            return [
                'success' => false,
                'error' => 'Nenhum telefone de WhatsApp válido cadastrado para este cliente.',
            ];
        }

        $message = $this->buildMessage($invoice, $customMessage);

        // 1. Send text message
        $result = $this->driver->sendTextMessage($recipientPhone, $message);

        if (!$result['success']) {
            Log::error("Failed to send WhatsApp message for invoice {$invoice->id}: " . ($result['error'] ?? 'Unknown error'));
            return $result;
        }

        // 2. Send PDF attachment if requested
        $attachPdf = $sendPdf !== null ? $sendPdf : $this->config['send_pdf'];
        if ($attachPdf) {
            try {
                $pdfBytes = (new GetInvoicePdf($invoice))->run();
                $filename = 'Fatura_' . ($invoice->number ?: $invoice->id) . '.pdf';
                $caption = 'Fatura ' . ($invoice->number ?: '#' . $invoice->id);

                $this->driver->sendDocument($recipientPhone, $pdfBytes, $filename, $caption);
            } catch (\Throwable $e) {
                Log::warning("Could not attach PDF in WhatsApp message: " . $e->getMessage());
            }
        }

        // 3. Send the Asaas Boleto attachment when requested/enabled
        $this->sendBoletoIfEnabled($invoice, $recipientPhone, $sendBoleto);

        // Mark invoice as sent if not already sent
        $invoice->service()->markSent()->save();

        SystemLogger::dispatch(
            [
                'invoice_id' => $invoice->id,
                'phone' => $recipientPhone,
                'provider' => $this->config['provider'],
                'result' => $result,
            ],
            SystemLog::CATEGORY_MAIL,
            SystemLog::EVENT_MAIL_SEND,
            SystemLog::TYPE_ASAAS,
            $invoice->client,
            $this->company
        );

        return [
            'success' => true,
            'message' => 'Fatura enviada com sucesso via WhatsApp para ' . $recipientPhone,
            'data' => $result,
        ];
    }

    /**
     * Build reminder message with template-specific text.
     */
    public function buildReminderMessage(Invoice $invoice, ?string $reminderTemplate = 'invoice'): string
    {
        $customTemplate = null;

        if ($reminderTemplate === 'reminder1') {
            $customTemplate = $this->config['template_reminder1'] ?? (
                "Olá, *:contact*!\n\n"
                . "Lembramos que a sua fatura *:invoice* no valor de *:amount* da empresa *:company* vencerá em *:due_date*.\n\n"
                . "Para facilitar, você pode efetuar o pagamento antecipado via *Pix ou Boleto* acessando o link:\n"
                . ":link\n\n"
                . "Qualquer dúvida, estamos à disposição!\n"
                . "*:company*"
            );
        } elseif ($reminderTemplate === 'reminder2') {
            $customTemplate = $this->config['template_reminder2'] ?? (
                "Olá, *:contact*!\n\n"
                . "Sua fatura *:invoice* no valor de *:amount* *vence hoje (:due_date)*.\n\n"
                . "Para evitar encargos, realize o pagamento via *Pix ou Boleto* no link:\n"
                . ":link\n\n"
                . "*:company*"
            );
        } elseif (in_array($reminderTemplate, ['reminder3', 'reminder_endless', 'endless_reminder'])) {
            $customTemplate = $this->config['template_reminder3'] ?? (
                "Olá, *:contact*!\n\n"
                . "Aviso de Fatura Pendente: sua fatura *:invoice* no valor de *:amount* com vencimento em *:due_date* ainda consta em aberto.\n\n"
                . "Acesse o link abaixo para visualizar a 2ª via atualizada e quitar sua fatura via *Pix ou Boleto*:\n"
                . ":link\n\n"
                . "*(Caso já tenha efetuado o pagamento, por favor desconsidere este aviso.)*\n"
                . "*:company*"
            );
        }

        return $this->buildMessage($invoice, $customTemplate);
    }

    /**
     * Send invoice reminder via WhatsApp.
     */
    public function sendInvoiceReminder(Invoice $invoice, ?string $reminderTemplate = 'invoice'): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'error' => 'WhatsApp não está configurado para esta empresa.',
            ];
        }

        $recipientPhone = $this->getRecipientPhone($invoice);
        if (empty($recipientPhone)) {
            return [
                'success' => false,
                'error' => 'Nenhum telefone de WhatsApp válido encontrado para o cliente.',
            ];
        }

        $message = $this->buildReminderMessage($invoice, $reminderTemplate);

        // 1. Send text message
        $result = $this->driver->sendTextMessage($recipientPhone, $message);

        if (!($result['success'] ?? false)) {
            Log::error("Failed to send WhatsApp reminder ({$reminderTemplate}) for invoice {$invoice->id}: " . ($result['error'] ?? ''));
            return $result;
        }

        // 2. Attach PDF if configured
        if ($this->config['send_pdf']) {
            try {
                $pdfBytes = (new GetInvoicePdf($invoice))->run();
                $filename = 'Fatura_' . ($invoice->number ?: $invoice->id) . '.pdf';
                $caption = 'Fatura ' . ($invoice->number ?: '#' . $invoice->id);

                $this->driver->sendDocument($recipientPhone, $pdfBytes, $filename, $caption);
            } catch (\Throwable $e) {
                Log::warning("Could not attach PDF in WhatsApp reminder: " . $e->getMessage());
            }
        }

        // 3. Send the Asaas Boleto attachment when enabled
        $this->sendBoletoIfEnabled($invoice, $recipientPhone);

        SystemLogger::dispatch(
            [
                'invoice_id' => $invoice->id,
                'phone' => $recipientPhone,
                'template' => $reminderTemplate,
                'provider' => $this->config['provider'],
                'result' => $result,
            ],
            SystemLog::CATEGORY_MAIL,
            SystemLog::EVENT_REMIND_INVOICE,
            SystemLog::TYPE_ASAAS,
            $invoice->client,
            $this->company
        );

        return [
            'success' => true,
            'message' => "Lembrete ({$reminderTemplate}) enviado via WhatsApp com sucesso para {$recipientPhone}",
            'data' => $result,
        ];
    }

    /**
     * Send the Asaas Boleto as a document when the company has it enabled.
     */
    protected function sendBoletoIfEnabled(Invoice $invoice, ?string $phone, ?bool $sendBoleto = null): void
    {
        $enabled = $sendBoleto !== null ? $sendBoleto : ($this->config['send_boleto'] ?? false);

        if (!$enabled || empty($phone) || !$this->driver) {
            return;
        }

        try {
            $boleto = AsaasBoletoService::forInvoice($invoice)->resolvePdf();

            if (!$boleto) {
                return;
            }

            $caption = 'Boleto da Fatura ' . ($invoice->number ?: '#' . $invoice->id);
            if (!empty($boleto['linha_digitavel'])) {
                $caption .= "\nLinha digitável: " . $boleto['linha_digitavel'];
            }

            $filename = $boleto['filename'] ?? ('Boleto_' . ($invoice->number ?: $invoice->id) . '.pdf');

            if (!empty($boleto['pdf'])) {
                $this->driver->sendDocument($phone, $boleto['pdf'], $filename, $caption);
            } elseif (!empty($boleto['url'])) {
                $this->driver->sendDocument($phone, $boleto['url'], $filename, $caption);
            }
        } catch (\Throwable $e) {
            Log::warning("Could not attach Boleto in WhatsApp message: " . $e->getMessage());
        }
    }

    /**
     * Test connection to the active WhatsApp provider.
     */
    public function testConnection(): array
    {
        if (!$this->driver) {
            return [
                'success' => false,
                'message' => 'Driver do WhatsApp não inicializado. Verifique as credenciais.',
            ];
        }

        return $this->driver->testConnection();
    }

    /**
     * Send test message to a specified phone number.
     */
    public function sendTestMessage(string $phone, string $message): array
    {
        if (!$this->driver) {
            return [
                'success' => false,
                'error' => 'WhatsApp não configurado.',
            ];
        }

        $normalized = self::normalizePhoneNumber($phone);
        if (!$normalized) {
            return [
                'success' => false,
                'error' => 'Número de telefone inválido.',
            ];
        }

        return $this->driver->sendTextMessage($normalized, $message);
    }

    /**
     * Retrieve QR Code or pairing code for instance (Evolution API).
     */
    public function getQrCode(): array
    {
        if ($this->config['provider'] !== 'evolution' || !($this->driver instanceof EvolutionApiDriver)) {
            return [
                'success' => false,
                'error' => 'O pareamento via QR Code está disponível apenas para o provedor Evolution API.',
            ];
        }

        return $this->driver->getQrCode();
    }

    /**
     * Check live connection status of WhatsApp instance.
     */
    public function getConnectionStatus(): array
    {
        if ($this->config['provider'] === 'evolution' && $this->driver instanceof EvolutionApiDriver) {
            return $this->driver->getConnectionState();
        }

        if ($this->config['provider'] === 'meta' && $this->driver instanceof MetaApiDriver) {
            $test = $this->driver->testConnection();
            return [
                'success' => $test['success'],
                'state' => $test['success'] ? 'connected' : 'error',
                'connected' => $test['success'],
                'message' => $test['message'] ?? '',
            ];
        }

        return [
            'success' => false,
            'state' => 'unconfigured',
            'connected' => false,
            'error' => 'WhatsApp não configurado.',
        ];
    }

    /**
     * Disconnect WhatsApp session (Evolution API).
     */
    public function disconnect(): array
    {
        if ($this->config['provider'] !== 'evolution' || !($this->driver instanceof EvolutionApiDriver)) {
            return [
                'success' => false,
                'error' => 'A desconexão de sessão está disponível apenas para o provedor Evolution API.',
            ];
        }

        return $this->driver->disconnectInstance();
    }
}

