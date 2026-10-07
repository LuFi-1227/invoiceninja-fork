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

namespace App\Services\WhatsApp\Contracts;

interface WhatsAppDriverInterface
{
    /**
     * Send a text message to a WhatsApp number.
     *
     * @param string $phoneNumber Normalized phone number (e.g. 5511999998888)
     * @param string $message Text content
     * @return array Response data including success bool and message ID or error
     */
    public function sendTextMessage(string $phoneNumber, string $message): array;

    /**
     * Send a PDF document / invoice to a WhatsApp number.
     *
     * @param string $phoneNumber Normalized phone number
     * @param string $pdfContent Raw binary string or public URL of the PDF
     * @param string $filename Name of the file (e.g. Fatura_0001.pdf)
     * @param string $caption Optional caption message
     * @return array Response data
     */
    public function sendDocument(string $phoneNumber, string $pdfContent, string $filename, string $caption = ''): array;

    /**
     * Test connection to the WhatsApp API.
     *
     * @return array ['success' => bool, 'message' => string]
     */
    public function testConnection(): array;
}
