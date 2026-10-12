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
                return null;
            }

            $data = $service->all();

            $boleto = $data['boleto'] ?? null;
            $pix = $data['pix'] ?? null;

            if (! $boleto && ! $pix) {
                return null;
            }

            return static::buildHtml($invoice, $boleto, $pix);
        } catch (\Throwable $e) {
            Log::warning('AsaasPaymentBlock build failed: ' . $e->getMessage());

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

        return '<div class="asaas-payment-block" style="margin-top:18px;padding-top:12px;border-top:2px solid #e5e7eb;">'
            . '<div style="margin-bottom:8px;font-size:12px;font-weight:700;color:#374151;">'
            . 'Formas de pagamento &mdash; Asaas'
            . ($invoice->number ? ' &middot; Fatura ' . e($invoice->number) : '')
            . ' &middot; Valor: ' . e($amount)
            . '</div>'
            . '<table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation"><tr>'
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

        $barcodeImg = $barcode !== ''
            ? '<img src="data:image/png;base64,' . static::barcodePngDataUri(preg_replace('/\D/', '', $barcode)) . '"'
                . ' alt="Código de barras" style="width:100%;max-width:300px;height:70px;display:block;margin:6px 0;" />'
            : '';

        return '<td width="50%" valign="top" style="border:1px solid #e5e7eb;border-radius:6px;padding:10px;box-sizing:border-box;">'
            . '<div style="font-size:11px;font-weight:700;color:#b45309;margin-bottom:6px;">Boleto banc&aacute;rio</div>'
            . '<div style="font-size:9px;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;">Linha digit&aacute;vel</div>'
            . '<div style="font-family:courier,monospace;font-size:11px;letter-spacing:.5px;word-wrap:break-word;margin-bottom:4px;">' . e($linha !== '' ? $linha : ($barcode !== '' ? $barcode : '&mdash;')) . '</div>'
            . $barcodeImg
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

        $qr = $image !== ''
            ? '<img src="data:image/png;base64,' . $image . '" alt="QR Code Pix" style="width:130px;height:130px;display:block;margin:6px 0;" />'
            : '';

        return '<td width="50%" valign="top" style="border:1px solid #e5e7eb;border-radius:6px;padding:10px;box-sizing:border-box;">'
            . '<div style="font-size:11px;font-weight:700;color:#047857;margin-bottom:6px;">Pix</div>'
            . $qr
            . ($payload !== ''
                ? '<div style="font-size:9px;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;">Pix copia e cola</div>'
                    . '<div style="font-family:courier,monospace;font-size:8px;word-wrap:break-word;overflow-wrap:break-word;color:#374151;">' . e($payload) . '</div>'
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