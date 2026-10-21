<?php

namespace App\Mail\Client;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotaFiscalMail extends Mailable
{
    use Queueable, SerializesModels;

    public Invoice $invoice;
    public array $nfData;
    public string $paymentMethod;

    public function __construct(Invoice $invoice, array $nfData, string $paymentMethod = '')
    {
        $this->invoice = $invoice;
        $this->nfData = $nfData;
        $this->paymentMethod = $paymentMethod;
    }

    public function build()
    {
        $company = $this->invoice->company;
        $settings = $company ? $company->settings : new \stdClass();
        $client = $this->invoice->client;
        $contact = $client ? $client->contacts()->first() : null;

        $nfNumber = (string) ($this->nfData['number'] ?? '');
        $pdfUrl = (string) ($this->nfData['pdfUrl'] ?? '');
        $xmlUrl = (string) ($this->nfData['xmlUrl'] ?? '');
        $value = (float) ($this->nfData['value'] ?? $this->invoice->amount);
        $validationCode = (string) ($this->nfData['validationCode'] ?? '');

        $subject = "Nota Fiscal de Serviço emitida - Fatura #{$this->invoice->number}" . ($nfNumber ? " (NFS-e Nº {$nfNumber})" : '');

        $mail = $this->subject($subject)
            ->view('email.client.nota_fiscal', [
                'invoice' => $this->invoice,
                'client' => $client,
                'contact' => $contact,
                'company' => $company,
                'settings' => $settings,
                'logo' => $company ? $company->present()->logo() : '',
                'greeting' => 'Olá, ' . ($client ? $client->present()->name() : 'Cliente') . '!',
                'nf_number' => $nfNumber,
                'validation_code' => $validationCode,
                'value' => $value,
                'payment_method' => $this->paymentMethod,
                'url' => $pdfUrl,
                'xml_url' => $xmlUrl,
                'signature' => $company ? ($company->owner() ? $company->owner()->signature : '') : '',
            ]);

        // Download and attach PDF directly to the email if available
        if (!empty($pdfUrl)) {
            try {
                $response = Http::timeout(30)->get($pdfUrl);
                if ($response->successful() && !empty($response->body())) {
                    $filename = 'Nota_Fiscal_' . ($nfNumber ?: $this->invoice->number) . '.pdf';
                    $mail->attachData($response->body(), $filename, [
                        'mime' => 'application/pdf',
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning("Could not download Asaas Nota Fiscal PDF for attachment: " . $e->getMessage());
            }
        }

        return $mail;
    }
}
