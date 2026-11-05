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
        $blocks = '';

        if ($boleto) {
            $blocks .= static::boletoBlock($boleto);
        }

        if ($pix) {
            if ($blocks !== '') {
                $blocks .= '<div style="height:5px;"></div>';
            }
            $blocks .= static::pixBlock($pix);
        }

        if ($blocks === '') {
            return '';
        }

        $rawAmount = (float) ($boleto['value'] ?? ($pix['value'] ?? $invoice->balance));
        $amount = $invoice->client ? Number::formatMoney($rawAmount, $invoice->client) : 'R$ ' . number_format($rawAmount, 2, ',', '.');

        return '<div class="asaas-payment-block" style="page-break-inside:avoid;margin-top:6px;padding-top:4px;border-top:1px solid #e5e7eb;">'
            . '<div style="margin-bottom:4px;font-size:10.5px;font-weight:700;color:#374151;">'
            . 'Formas de pagamento &mdash; Asaas'
            . ($invoice->number ? ' &middot; Fatura ' . e($invoice->number) : '')
            . ' &middot; Valor: ' . e($amount)
            . '</div>'
            . $blocks
            . '</div>';
    }

    private static function boletoBlock(array $boleto): string
    {
        $linha = (string) ($boleto['linha_digitavel'] ?? '');
        $barcode = (string) ($boleto['barcode'] ?? '');
        $due = isset($boleto['due_date']) ? date('d/m/Y', strtotime((string) $boleto['due_date'])) : '';
        $value = isset($boleto['value']) ? 'R$ ' . number_format((float) $boleto['value'], 2, ',', '.') : '';

        $barcodeDigits = preg_replace('/\D/', '', $barcode !== '' ? $barcode : $linha);
        $barcodeImg = $barcodeDigits !== ''
            ? '<img src="data:image/png;base64,' . static::barcodePngDataUri($barcodeDigits) . '"'
                . ' alt="Código de barras" style="width:100%;height:48px;display:block;margin:3px 0;image-rendering:pixelated;" />'
            : '';

        $barcodeInfo = ($barcode !== '' && $barcode !== $linha)
            ? '<div style="font-size:7.5px;color:#6b7280;margin-top:2px;text-align:center;">C&oacute;digo de barras: <span style="font-family:courier,monospace;color:#374151;">' . e($barcode) . '</span></div>'
            : '';

        $metaRight = [];
        if ($due !== '') {
            $metaRight[] = 'Vencimento: <b>' . e($due) . '</b>';
        }
        if ($value !== '') {
            $metaRight[] = 'Valor: <b>' . e($value) . '</b>';
        }
        $metaRightHtml = ! empty($metaRight)
            ? '<td align="right" style="font-size:9.5px;color:#374151;">' . implode(' &nbsp;&middot;&nbsp; ', $metaRight) . '</td>'
            : '';

        return '<table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation" style="width:100%;border:1px solid #e5e7eb;border-radius:4px;padding:6px 8px;box-sizing:border-box;background:#ffffff;">'
            . '<tr><td>'
            . '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:3px;"><tr>'
            . '<td align="left" style="font-size:10.5px;font-weight:700;color:#b45309;">Boleto banc&aacute;rio</td>'
            . $metaRightHtml
            . '</tr></table>'
            . '<div style="font-size:8px;color:#6b7280;text-transform:uppercase;letter-spacing:.3px;margin-bottom:2px;">Linha digit&aacute;vel</div>'
            . '<div style="font-family:courier,monospace;font-size:10px;letter-spacing:.3px;word-wrap:break-word;overflow-wrap:break-word;word-break:break-all;margin-bottom:3px;color:#111827;font-weight:700;background:#f9fafb;padding:3px 6px;border-radius:3px;border:1px solid #e5e7eb;">' . e($linha !== '' ? $linha : ($barcode !== '' ? $barcode : '&mdash;')) . '</div>'
            . '<div style="width:100%;text-align:center;overflow:hidden;margin:3px 0;">'
            . $barcodeImg
            . '</div>'
            . $barcodeInfo
            . '</td></tr></table>';
    }

    private static function pixBlock(array $pix): string
    {
        $image = (string) ($pix['encoded_image'] ?? '');
        $payload = (string) ($pix['payload'] ?? '');
        $due = isset($pix['due_date']) ? date('d/m/Y', strtotime((string) $pix['due_date'])) : '';

        $imgSrc = $image !== ''
            ? (str_starts_with($image, 'data:image/') ? $image : 'data:image/png;base64,' . $image)
            : '';

        $qr = $imgSrc !== ''
            ? '<img src="' . $imgSrc . '" alt="QR Code Pix" style="width:82px;height:82px;display:block;border:1px solid #e5e7eb;border-radius:3px;padding:2px;background:#ffffff;" />'
            : '';

        $qrCell = $qr !== ''
            ? '<td width="92" valign="top" style="width:92px;padding-right:8px;">'
                . '<div style="font-size:10px;font-weight:700;color:#047857;margin-bottom:4px;">Pix (QR Code)</div>'
                . $qr
                . '</td>'
            : '';

        $pixHeaderTitle = $qr !== '' ? '' : '<div style="font-size:10px;font-weight:700;color:#047857;margin-bottom:4px;">Pix</div>';

        return '<table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation" style="width:100%;border:1px solid #e5e7eb;border-radius:4px;padding:6px 8px;box-sizing:border-box;background:#ffffff;">'
            . '<tr>'
            . $qrCell
            . '<td valign="top">'
            . $pixHeaderTitle
            . '<table width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td align="left" style="font-size:9.5px;color:#374151;">Pague escaneando o QR Code ao lado pelo app do seu banco ou use o Copia e Cola:</td>'
            . ($due !== '' ? '<td align="right" style="font-size:9.5px;color:#374151;white-space:nowrap;padding-left:6px;">Vencimento: <b>' . e($due) . '</b></td>' : '')
            . '</tr></table>'
            . ($payload !== ''
                ? '<div style="font-size:8px;color:#6b7280;text-transform:uppercase;letter-spacing:.3px;margin-top:4px;margin-bottom:2px;">C&oacute;digo Pix (Copia e Cola)</div>'
                    . '<div style="font-family:courier,monospace;font-size:7.5px;word-wrap:break-word;overflow-wrap:break-word;word-break:break-all;color:#374151;background:#f9fafb;padding:4px 6px;border-radius:3px;border:1px solid #e5e7eb;line-height:1.25;">' . e($payload) . '</div>'
                : '')
            . '</td>'
            . '</tr></table>';
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