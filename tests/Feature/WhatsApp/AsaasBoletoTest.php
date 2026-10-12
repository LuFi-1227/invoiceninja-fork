<?php

namespace Tests\Feature\WhatsApp;

use App\Models\CompanyGateway;
use App\Models\PaymentHash;
use App\PaymentDrivers\Asaas\AsaasApi;
use App\Services\Asaas\AsaasBoletoService;
use App\Services\WhatsApp\Drivers\EvolutionApiDriver;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\MockAccountData;
use Tests\TestCase;

class AsaasBoletoTest extends TestCase
{
    use MockAccountData;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeTestData();

        config([
            'ninja.whatsapp.enabled' => true,
            'ninja.whatsapp.provider' => 'evolution',
            'ninja.whatsapp.send_pdf' => false,
            'ninja.whatsapp.send_boleto' => true,
            'ninja.whatsapp.evolution.api_url' => 'https://evolution.test',
            'ninja.whatsapp.evolution.api_key' => 'test-key',
            'ninja.whatsapp.evolution.instance' => 'InvoiceNinja',
        ]);

        $this->createAsaasGateway();

        $this->client->phone = '11987654321';
        $this->client->save();
    }

    private function createAsaasGateway(): void
    {
        $config = new \stdClass();
        $config->apiKey = 'asaas-test-key';
        $config->webhookToken = 'wh-token';
        $config->testMode = false;

        $cg = new CompanyGateway();
        $cg->company_id = $this->company->id;
        $cg->user_id = $this->user->id;
        $cg->gateway_key = AsaasBoletoService::GATEWAY_KEY;
        $cg->require_cvv = false;
        $cg->require_billing_address = false;
        $cg->update_details = false;
        $cg->setConfig($config);
        $cg->fees_and_limits = '';
        $cg->save();
    }

    private function fakeAsaas(): void
    {
        Http::fake([
            'api.asaas.com/v3/customers*' => function ($request) {
                return $request->method() === 'POST'
                    ? Http::response(['id' => 'cus_123'], 200)
                    : Http::response(['data' => []], 200);
            },
            'api.asaas.com/v3/payments/pay_123/identificationField' => Http::response([
                'identificationField' => '00190500954014481606906809350314337370000000100',
                'barCode' => '00193373700000001000500940144816060680935031',
            ], 200),
            'api.asaas.com/v3/payments/pay_123' => Http::response([
                'id' => 'pay_123',
                'status' => 'PENDING',
                'dueDate' => '2026-12-31',
                'bankSlipUrl' => 'https://asaas.test/boleto/pay_123.pdf',
            ], 200),
            'api.asaas.com/v3/payments' => Http::response([
                'id' => 'pay_123',
                'status' => 'PENDING',
                'dueDate' => '2026-12-31',
                'bankSlipUrl' => 'https://asaas.test/boleto/pay_123.pdf',
            ], 200),
            'asaas.test/boleto*' => Http::response('%PDF-1.4 fake boleto content', 200),
            'evolution.test/*' => Http::response(['key' => ['id' => 'msg_1']], 200),
        ]);
    }

    public function test_resolve_boleto_for_invoice(): void
    {
        $this->fakeAsaas();

        $boleto = AsaasBoletoService::forInvoice($this->invoice->fresh())->resolvePdf();

        $this->assertNotNull($boleto);
        $this->assertSame('pay_123', $boleto['payment_id']);
        $this->assertSame('00190500954014481606906809350314337370000000100', $boleto['linha_digitavel']);
        $this->assertSame('https://asaas.test/boleto/pay_123.pdf', $boleto['url']);
        $this->assertSame('%PDF-1.4 fake boleto content', $boleto['pdf']);

        $this->assertDatabaseHas('payment_hashes', [
            'fee_invoice_id' => $this->invoice->id,
        ]);
    }

    public function test_boleto_is_reused_between_calls(): void
    {
        $this->fakeAsaas();

        AsaasBoletoService::forInvoice($this->invoice->fresh())->resolve();
        AsaasBoletoService::forInvoice($this->invoice->fresh())->resolve();

        // Payment creation should only happen once for a still-pending boleto.
        $paymentPosts = 0;
        Http::assertSent(function ($request) use (&$paymentPosts) {
            if ($request->url() === 'https://api.asaas.com/v3/payments' && $request->method() === 'POST') {
                $paymentPosts++;
            }

            return true;
        });

        $this->assertSame(1, $paymentPosts);
    }

    public function test_client_asaas_id_is_persisted_and_reused(): void
    {
        $this->fakeAsaas();

        $this->assertEmpty($this->client->fresh()->asaas_id);

        $api = new AsaasApi('asaas-test-key');

        $this->assertSame('cus_123', $api->createOrUpdateCustomer($this->client->fresh()));
        $this->assertSame('cus_123', $this->client->fresh()->asaas_id);

        // A subsequent call must reuse the stored id and never POST a new customer.
        $this->assertSame('cus_123', $api->createOrUpdateCustomer($this->client->fresh()));

        $customerPosts = 0;
        Http::assertSent(function ($request) use (&$customerPosts) {
            if ($request->method() === 'POST' && str_contains($request->url(), '/customers')) {
                $customerPosts++;
            }

            return true;
        });

        $this->assertSame(1, $customerPosts);
    }

    public function test_whatsapp_sends_invoice_text_and_boleto_document(): void
    {
        $this->fakeAsaas();

        $service = new WhatsAppService($this->company->fresh());
        $result = $service->sendInvoice($this->invoice->fresh());

        $this->assertTrue($result['success']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/message/sendText/InvoiceNinja')
                && $request['number'] === '5511987654321';
        });

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), '/message/sendMedia/InvoiceNinja')
                && $body['mediatype'] === 'document'
                && str_contains($body['caption'], 'Linha digitável');
        });
    }

    public function test_driver_sends_boleto_pdf_document(): void
    {
        Http::fake(['*evolution.test*' => Http::response(['key' => ['id' => 'msg_1']], 200)]);

        $driver = new EvolutionApiDriver('https://evolution.test', 'test-key', 'InvoiceNinja');

        $result = $driver->sendDocument('5511987654321', '%PDF-1.4 boleto', 'Boleto_1.pdf', 'Boleto');

        $this->assertTrue($result['success']);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), '/message/sendMedia/InvoiceNinja')
                && $body['fileName'] === 'Boleto_1.pdf'
                && str_starts_with($body['media'], 'data:application/pdf;base64,');
        });
    }
}
