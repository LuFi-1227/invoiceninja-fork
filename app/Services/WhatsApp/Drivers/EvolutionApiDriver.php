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

class EvolutionApiDriver implements WhatsAppDriverInterface
{
    protected string $apiUrl;
    protected string $apiKey;
    protected string $instance;

    public function __construct(string $apiUrl, string $apiKey, string $instance)
    {
        $this->apiUrl = rtrim(trim($apiUrl), '/');
        $this->apiKey = trim($apiKey);
        $this->instance = trim($instance);
    }

    /**
     * Send text message via Evolution API.
     */
    public function sendTextMessage(string $phoneNumber, string $message): array
    {
        try {
            $endpoint = "{$this->apiUrl}/message/sendText/{$this->instance}";

            $response = Http::withHeaders([
                'apikey' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(20)->post($endpoint, [
                'number' => $phoneNumber,
                'text' => $message,
            ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'message_id' => $response->json('key.id') ?? $response->json('messageId') ?? 'sent',
                    'data' => $response->json(),
                ];
            }

            Log::error('EvolutionAPI sendText error: ' . $response->body());
            return [
                'success' => false,
                'error' => $response->json('response.message') ?? $response->json('error') ?? $response->body(),
            ];
        } catch (\Throwable $e) {
            Log::error('EvolutionAPI sendText exception: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send PDF document via Evolution API.
     */
    public function sendDocument(string $phoneNumber, string $pdfContent, string $filename, string $caption = ''): array
    {
        try {
            $endpoint = "{$this->apiUrl}/message/sendMedia/{$this->instance}";

            $media = str_starts_with($pdfContent, 'http')
                ? $pdfContent
                : 'data:application/pdf;base64,' . base64_encode($pdfContent);

            $payload = [
                'number' => $phoneNumber,
                'mediatype' => 'document',
                'mimetype' => 'application/pdf',
                'caption' => $caption,
                'media' => $media,
                'fileName' => $filename,
            ];

            $response = Http::withHeaders([
                'apikey' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(45)->post($endpoint, $payload);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'message_id' => $response->json('key.id') ?? $response->json('messageId') ?? 'sent',
                    'data' => $response->json(),
                ];
            }

            Log::error('EvolutionAPI sendMedia error: ' . $response->body());
            return [
                'success' => false,
                'error' => $response->json('response.message') ?? $response->json('error') ?? $response->body(),
            ];
        } catch (\Throwable $e) {
            Log::error('EvolutionAPI sendMedia exception: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Test instance connection in Evolution API.
     */
    public function testConnection(): array
    {
        try {
            $endpoint = "{$this->apiUrl}/instance/connectionState/{$this->instance}";

            $response = Http::withHeaders([
                'apikey' => $this->apiKey,
            ])->timeout(15)->get($endpoint);

            if ($response->successful()) {
                $state = $response->json('instance.state') ?? $response->json('state') ?? 'connected';
                return [
                    'success' => true,
                    'state' => $state,
                    'message' => "Evolution API conectada com sucesso! Estado da instância: {$state}.",
                ];
            }

            return [
                'success' => false,
                'message' => 'Falha ao consultar instância na Evolution API: ' . $response->body(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Erro ao conectar à Evolution API: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Retrieve QR Code / pairing code from Evolution API.
     */
    public function getQrCode(): array
    {
        try {
            $endpoint = "{$this->apiUrl}/instance/connect/{$this->instance}";

            $response = Http::withHeaders([
                'apikey' => $this->apiKey,
            ])->timeout(20)->get($endpoint);

            // If instance doesn't exist, create it automatically
            if ($response->status() === 404 || ($response->status() === 400 && str_contains($response->body(), 'not found'))) {
                $createEndpoint = "{$this->apiUrl}/instance/create";
                $createResponse = Http::withHeaders([
                    'apikey' => $this->apiKey,
                    'Content-Type' => 'application/json',
                ])->timeout(20)->post($createEndpoint, [
                    'instanceName' => $this->instance,
                    'qrcode' => true,
                    'integration' => 'WHATSAPP-BAILEYS',
                ]);

                if ($createResponse->successful()) {
                    $cData = $createResponse->json();
                    $qrcode = $cData['qrcode']['base64'] ?? $cData['base64'] ?? null;
                    $pairingCode = $cData['pairingCode'] ?? null;
                    $code = $cData['qrcode']['code'] ?? $cData['code'] ?? null;

                    return [
                        'success' => true,
                        'connected' => false,
                        'qrcode' => $qrcode,
                        'pairing_code' => $pairingCode,
                        'code' => $code,
                        'instance' => $this->instance,
                    ];
                }
            }

            if ($response->successful()) {
                $data = $response->json();
                $state = $data['instance']['state'] ?? $data['state'] ?? null;

                if ($state === 'open' || $state === 'connected') {
                    return [
                        'success' => true,
                        'connected' => true,
                        'state' => 'open',
                        'message' => 'Instância já está conectada ao WhatsApp!',
                        'instance' => $this->instance,
                    ];
                }

                $qrcode = $data['base64'] ?? $data['qrcode']['base64'] ?? null;
                $pairingCode = $data['pairingCode'] ?? null;
                $code = $data['code'] ?? $data['qrcode']['code'] ?? null;

                return [
                    'success' => true,
                    'connected' => false,
                    'qrcode' => $qrcode,
                    'pairing_code' => $pairingCode,
                    'code' => $code,
                    'instance' => $this->instance,
                ];
            }

            return [
                'success' => false,
                'connected' => false,
                'error' => $response->json('response.message') ?? $response->json('error') ?? $response->body(),
            ];
        } catch (\Throwable $e) {
            Log::error('EvolutionAPI getQrCode exception: ' . $e->getMessage());
            return [
                'success' => false,
                'connected' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get live connection state of the instance.
     */
    public function getConnectionState(): array
    {
        try {
            $endpoint = "{$this->apiUrl}/instance/connectionState/{$this->instance}";

            $response = Http::withHeaders([
                'apikey' => $this->apiKey,
            ])->timeout(10)->get($endpoint);

            if ($response->successful()) {
                $data = $response->json();
                $state = $data['instance']['state'] ?? $data['state'] ?? 'close';

                return [
                    'success' => true,
                    'state' => $state,
                    'connected' => in_array($state, ['open', 'connected']),
                    'instance' => $this->instance,
                ];
            }

            return [
                'success' => false,
                'state' => 'disconnected',
                'connected' => false,
                'error' => $response->body(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'state' => 'error',
                'connected' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Disconnect/logout instance from WhatsApp.
     */
    public function disconnectInstance(): array
    {
        try {
            $endpoint = "{$this->apiUrl}/instance/logout/{$this->instance}";

            $response = Http::withHeaders([
                'apikey' => $this->apiKey,
            ])->timeout(15)->delete($endpoint);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'message' => 'Instância desconectada com sucesso.',
                ];
            }

            return [
                'success' => false,
                'error' => $response->json('response.message') ?? $response->body(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}

