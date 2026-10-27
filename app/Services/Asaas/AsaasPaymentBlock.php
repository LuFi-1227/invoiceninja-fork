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

use App\Models\Invoice;
use App\Models\PaymentHash;
use App\Utils\Number;
use Illuminate\Support\Facades\Log;

/**
 * Builds an HTML block with the Asaas payment methods (Boleto barcode /
 * "linha digitável" and Pix QR Code / copy-paste) to be plotted on the
 * system generated invoice PDF when the Asaas gateway is enabled.
 *
 * The block is a self-contained HTML string with inline styles so it works
 * with any PDF engine (hosted/chromium, dompdf, gotenberg).
 */
class AsaasPaymentBlock
{
    /**
     * Build the payment block for an invoice, or null when Asaas is not
     * enabled / no charge could be resolved. Never throws.
     */
    public static function forInvoice(Invoice $invoice): ?string
    {
        try {
            $service = AsaasBoletoService::forInvoice($invoice);

            if (! $service->isAvailable()) {
                return static::forInvoiceFromHashes($invoice);
            }

            try {
                $data = $service->all();
                $boleto = $data['boleto'] ?? null;
                $pix = $data['pix'] ?? null;

                if ($boleto || $pix) {
                    return static::buildHtml($invoice, $boleto, $pix);
                }
            } catch (\Throwable $e) {
                Log::warning('AsaasPaymentBlock build failed: ' . $e->getMessage());
            }

            $hashBlock = static::forInvoiceFromHashes($invoice);
            if ($hashBlock) {
                return $hashBlock;
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning('AsaasPaymentBlock build failed: ' . $e->getMessage());

            return static::forInvoiceFromHashes($invoice);
        }
    }

    /**
     * Try to build the block from existing PaymentHash records (created by
     * the portal payment flow) so it still works even if the Asaas API call
     * to create/fetch charges fails in the current request.
     */
    public static function forInvoiceFromHashes(Invoice $invoice): ?string
    {
        try {
            $hashes = PaymentHash::query()
                ->where('fee_invoice_id', $invoice->id)
                ->get();

            if ($hashes->isEmpty()) {
                // Also try to find by invoice_id in data
                $hashes = PaymentHash::query()
                    ->where(function ($q) use ($invoice) {
                        $q->where('data->invoices', 'like', '%"invoice_id":"' . $invoice->hashed_id . '"%')
                          ->orWhere('data->invoices', 'like', '%"invoice_id":' . $invoice->id . '%');
                    })
                    ->get();
            }

            if ($hashes->isEmpty()) {
                return null;
            }

            $boleto = null;
            $pix = null;

            foreach ($hashes as $hash) {
                $data = (array) ($hash->data ?? []);

                if (! $boleto && (! empty($data['asaas_payment_id']) || ! empty($data['asaas_linha_digitavel']) || ! empty($data['asaas_bank_slip_url']))) {
                    $boleto = [
                        'linha_digitavel' => $data['asaas_linha_digitavel'] ?? '',
                        'barcode' => $data['asaas_barcode'] ?? '',
                        'due_date' => $data['asaas_due_date'] ?? $invoice->due_date,
                        'value' => $data['amount_with_fee'] ?? $invoice->balance,
                        'url' => $data['asaas_bank_slip_url'] ?? '',
                    ];
                }

                if (! $pix && (! empty($data['asaas_pix_payment_id']) || ! empty($data['asaas_pix_payload']) || ! empty($data['asaas_pix_image']))) {
                    $pix = [
                        'encoded_image' => $data['asaas_pix_image'] ?? $data['asaas_pix_encoded_image'] ?? '',
                        'payload' => $data['asaas_pix_payload'] ?? '',
                        'due_date' => $data['asaas_pix_due_date'] ?? $invoice->due_date,
                        'value' => $data['amount_with_fee'] ?? $invoice->balance,
                    ];
                }
            }

            if (! $boleto && ! $pix) {
                return null;
            }

            return static::buildHtml($invoice, $boleto, $pix);
        } catch (\Throwable $e) {
            Log::warning('AsaasPaymentBlock forInvoiceFromHashes failed: ' . $e->getMessage());

            return null;
        }
    }

    public static function buildHtml(Invoice $invoice, ?array $boleto, ?array $pix): string
    {
        $cells = '';

        if ($boleto) {
            $cells .= static::boletoCell($boleto);
        }

        if ($pix) {
            $cells .= static::pixCell($pix);
        }

        if ($cells === '') {
            return '';
        }

        $amount = Number::formatMoney($boleto['value'] ?? ($pix['value'] ?? $invoice->balance), $invoice->client);

        return '<div class="asaas-payment-block" style="page-break-inside:avoid;margin-top:18px;padding-top:12px;border-top:2px solid #e5e7eb;">'
            . '<div style="margin-bottom:8px;font-size:12px;font-weight:700;color:#374151;">'
            . 'Formas de pagamento &mdash; Asaas'
            . ($invoice->number ? ' &middot; Fatura ' . e($invoice->number) : '')
            . ' &middot; Valor: ' . e($amount)
            . '</div>'
            . '<table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation" style="width:100%;table-layout:fixed;border-collapse:separate;border-spacing:8px 0;"><tr>'
            . $cells
            . '</tr></table>'
            . '</div>';
    }

    private static function boletoCell(array $boleto): string
    {
        $linha = (string) ($boleto['linha_digitavel'] ?? '');
        $barcode = (string) ($boleto['barcode'] ?? '');
        $due = isset($boleto['due_date']) ? date('d/m/Y', strtotime((string) $boleto['due_date'])) : '';
        $value = isset($boleto['value']) ? 'R$ ' . number_format((float) $boleto['value'], 2, ',', '.') : '';

        $barcodeDigits = preg_replace('/\D/', '', $barcode !== '' ? $barcode : $linha);
        $barcodeImg = $barcodeDigits !== ''
            ? '<img src="data:image/png;base64,' . static::barcodePngDataUri($barcodeDigits) . '"'
                . ' alt="Código de barras" style="width:100%;max-width:320px;height:55px;display:block;margin:6px 0;" />'
            : '';

        $barcodeInfo = ($barcode !== '' && $barcode !== $linha)
            ? '<div style="font-size:8px;color:#6b7280;margin-top:2px;">C&oacute;digo de barras: <span style="font-family:courier,monospace;color:#374151;">' . e($barcode) . '</span></div>'
            : '';

        return '<td width="50%" valign="top" style="width:50%;border:1px solid #e5e7eb;border-radius:6px;padding:10px;box-sizing:border-box;">'
            . '<div style="font-size:11px;font-weight:700;color:#b45309;margin-bottom:6px;">Boleto banc&aacute;rio</div>'
            . '<div style="font-size:9px;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;">Linha digit&aacute;vel</div>'
            . '<div style="font-family:courier,monospace;font-size:10px;letter-spacing:.3px;word-wrap:break-word;overflow-wrap:break-word;word-break:break-all;margin-bottom:4px;color:#111827;font-weight:600;">' . e($linha !== '' ? $linha : ($barcode !== '' ? $barcode : '&mdash;')) . '</div>'
            . $barcodeImg
            . $barcodeInfo
            . '<div style="font-size:9px;color:#6b7280;margin-top:4px;">'
            . ($due !== '' ? '<div>Vencimento: <b>' . e($due) . '</b></div>' : '')
            . ($value !== '' ? '<div>Valor: <b>' . e($value) . '</b></div>' : '')
            . '</div>'
            . '</td>';
    }

    private static function pixCell(array $pix): string
    {
        $image = (string) ($pix['encoded_image'] ?? '');
        $payload = (string) ($pix['payload'] ?? '');
        $due = isset($pix['due_date']) ? date('d/m/Y', strtotime((string) $pix['due_date'])) : '';

        $imgSrc = $image !== ''
            ? (str_starts_with($image, 'data:image/') ? $image : 'data:image/png;base64,' . $image)
            : '';

        $qr = $imgSrc !== ''
            ? '<img src="' . $imgSrc . '" alt="QR Code Pix" style="width:120px;height:120px;display:block;margin:6px 0;" />'
            : '';

        return '<td width="50%" valign="top" style="width:50%;border:1px solid #e5e7eb;border-radius:6px;padding:10px;box-sizing:border-box;">'
            . '<div style="font-size:11px;font-weight:700;color:#047857;margin-bottom:6px;">Pix</div>'
            . $qr
            . ($payload !== ''
                ? '<div style="font-size:9px;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;">C&oacute;digo Pix (Copia e Cola)</div>'
                    . '<div style="font-family:courier,monospace;font-size:8px;word-wrap:break-word;overflow-wrap:break-word;word-break:break-all;color:#374151;background:#f9fafb;padding:4px 6px;border-radius:4px;border:1px solid #e5e7eb;margin-top:2px;">' . e($payload) . '</div>'
                : '')
            . ($due !== '' ? '<div style="font-size:9px;color:#6b7280;margin-top:4px;">Vencimento: <b>' . e($due) . '</b></div>' : '')
            . '</td>';
    }

    /**
     * Encode a numeric string as an Interleaved 2 of 5 barcode PNG (the
     * standard encoding for Brazilian bank boletos) and return a data URI.
     */
    public static function barcodePngDataUri(string $digits): string
    {
        $digits = preg_replace('/\D/', '', $digits);

        if ($digits === '') {
            return '';
        }

        if (strlen($digits) % 2 !== 0) {
            $digits = '0' . $digits;
        }

        try {
            $runs = static::itfRuns($digits);

            $quiet = 10;
            $scale = 2;
            $height = 70;
            $width = $quiet * 2 + array_sum(array_column($runs, 0)) * $scale;

            $img = imagecreatetruecolor($width, $height);
            if ($img === false) {
                return '';
            }

            $white = imagecolorallocate($img, 255, 255, 255);
            $black = imagecolorallocate($img, 0, 0, 0);
            imagefilledrectangle($img, 0, 0, $width, $height, $white);

            $x = $quiet * $scale;

            foreach ($runs as [$unit, $bar]) {
                $w = $unit * $scale;
                if ($bar) {
                    imagefilledrectangle($img, $x, 0, $x + $w - 1, $height, $black);
                }
                $x += $w;
            }

            ob_start();
            imagepng($img);
            $png = (string) ob_get_clean();
            imagedestroy($img);

            return base64_encode($png);
        } catch (\Throwable $e) {
            Log::warning('AsaasPaymentBlock barcode failed: ' . $e->getMessage());

            return '';
        }
    }

    /**
     * Interleaved 2 of 5 run-length encoding. Returns [[widthInUnits, isBar], ...].
     */
    private static function itfRuns(string $digits): array
    {
        $map = [
            '0' => '00110',
            '1' => '10001',
            '2' => '01001',
            '3' => '11000',
            '4' => '00101',
            '5' => '10100',
            '6' => '01100',
            '7' => '00011',
            '8' => '10010',
            '9' => '01010',
        ];

        $narrow = 1;
        $wide = 3;
        $runs = [];

        // Start guard: bar, space, bar, space (all narrow).
        foreach ([1, 1, 1, 1] as $i => $w) {
            $runs[] = [$w * $narrow, ($i % 2) === 0];
        }

        for ($i = 0; $i < strlen($digits); $i += 2) {
            $barBits = $map[$digits[$i]];
            $spaceBits = $map[$digits[$i + 1]];

            for ($k = 0; $k < 5; $k++) {
                $barWidth = $barBits[$k] === '1' ? $wide : $narrow;
                $spaceWidth = $spaceBits[$k] === '1' ? $wide : $narrow;
                $runs[] = [$barWidth, true];
                $runs[] = [$spaceWidth, false];
            }
        }

        // Stop guard: wide bar, narrow space, narrow bar.
        $runs[] = [$wide, true];
        $runs[] = [$narrow, false];
        $runs[] = [$narrow, true];

        return $runs;
    }
}