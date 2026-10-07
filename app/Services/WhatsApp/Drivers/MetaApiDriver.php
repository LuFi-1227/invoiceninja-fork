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

namespace App\Services\WhatsApp\Drivers;

use App\Services\WhatsApp\Contracts\WhatsAppDriverInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaApiDriver implements WhatsAppDriverInterface
{
    protected string $phoneNumberId;
    protected string $accessToken;
    protected string $apiVersion;
    protected string $baseUrl;

    public function __construct(string $phoneNumberId, string $accessToken, string $apiVersion = 'v19.0')
    {
        $this->phoneNumberId = trim($phoneNumberId);
        $this->accessToken = trim($accessToken);
        $this->apiVersion = trim($apiVersion);
        $this->baseUrl = "https://graph.facebook.com/{$this->apiVersion}";
    }

    /**
     * Send text message via Meta WhatsApp Cloud API.
     */
    public function sendTextMessage(string $phoneNumber, string $message): array
    {
        try {
            $endpoint = "{$this->baseUrl}/{$this->phoneNumberId}/messages";

            $payload = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $phoneNumber,
                'type' => 'text',
                'text' => [
                    'preview_url' => true,
                    'body' => $message,
                ],
            ];

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->accessToken}",
                'Content-Type' => 'application/json',
            ])->timeout(20)->post($endpoint, $payload);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'message_id' => $response->json('messages.0.id') ?? 'sent',
                    'data' => $response->json(),
                ];
            }

            Log::error('MetaAPI sendText error: ' . $response->body());
            return [
                'success' => false,
                'error' => $response->json('error.message') ?? $response->body(),
            ];
        } catch (\Throwable $e) {
            Log::error('MetaAPI sendText exception: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send document via Meta WhatsApp Cloud API.
     */
    public function sendDocument(string $phoneNumber, string $pdfContent, string $filename, string $caption = ''): array
    {
        try {
            $endpoint = "{$this->baseUrl}/{$this->phoneNumberId}/messages";

            // If it's already an accessible URL
            if (str_starts_with($pdfContent, 'http')) {
                $payload = [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $phoneNumber,
                    'type' => 'document',
                    'document' => [
                        'link' => $pdfContent,
                        'filename' => $filename,
                        'caption' => $caption,
                    ],
                ];
            } else {
                // Upload media buffer first to Meta
                $mediaUploadUrl = "{$this->baseUrl}/{$this->phoneNumberId}/media";
                $mediaResponse = Http::withHeaders([
                    'Authorization' => "Bearer {$this->accessToken}",
                ])->attach('file', $pdfContent, $filename, ['Content-Type' => 'application/pdf'])
                  ->post($mediaUploadUrl, [
                      'messaging_product' => 'whatsapp',
                      'type' => 'application/pdf',
                  ]);

                if (!$mediaResponse->successful()) {
                    Log::error('MetaAPI media upload failed: ' . $mediaResponse->body());
                    return [
                        'success' => false,
                        'error' => 'Falha ao enviar arquivo para o WhatsApp Cloud API: ' . $mediaResponse->body(),
                    ];
                }

                $mediaId = $mediaResponse->json('id');

                $payload = [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $phoneNumber,
                    'type' => 'document',
                    'document' => [
                        'id' => $mediaId,
                        'filename' => $filename,
                        'caption' => $caption,
                    ],
                ];
            }

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->accessToken}",
                'Content-Type' => 'application/json',
            ])->timeout(45)->post($endpoint, $payload);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'message_id' => $response->json('messages.0.id') ?? 'sent',
                    'data' => $response->json(),
                ];
            }

            Log::error('MetaAPI sendDocument error: ' . $response->body());
            return [
                'success' => false,
                'error' => $response->json('error.message') ?? $response->body(),
            ];
        } catch (\Throwable $e) {
            Log::error('MetaAPI sendDocument exception: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Test connection to Meta Cloud API.
     */
    public function testConnection(): array
    {
        try {
            $endpoint = "{$this->baseUrl}/{$this->phoneNumberId}";

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->accessToken}",
            ])->timeout(15)->get($endpoint);

            if ($response->successful()) {
                $verifiedName = $response->json('verified_name') ?? $response->json('display_phone_number') ?? 'OK';
                return [
                    'success' => true,
                    'message' => "Meta WhatsApp Cloud API conectada com sucesso! Número verificado: {$verifiedName}.",
                    'data' => $response->json(),
                ];
            }

            return [
                'success' => false,
                'message' => 'Falha na autenticação da Meta API: ' . ($response->json('error.message') ?? $response->body()),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Erro ao conectar à Meta API: ' . $e->getMessage(),
            ];
        }
    }
}
