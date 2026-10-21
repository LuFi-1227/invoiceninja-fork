<?php

namespace Tests\Feature\Asaas;

use App\Models\CompanyGateway;
use App\Models\Invoice;
use App\Models\PaymentHash;
use App\PaymentDrivers\Asaas\AsaasApi;
use App\PaymentDrivers\AsaasPaymentDriver;
use App\Services\Asaas\AsaasBoletoService;
use App\Services\Asaas\AsaasNotaFiscalService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\MockAccountData;
use Tests\TestCase;

class AsaasNotaFiscalTest extends TestCase
{
    use MockAccountData;
    use DatabaseTransactions;

    private CompanyGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeTestData();

        config([
            'ninja.whatsapp.enabled' => true,
            'ninja.whatsapp.provider' => 'evolution',
            'ninja.whatsapp.evolution.api_url' => 'https://evolution.test',
            'ninja.whatsapp.evolution.api_key' => 'test-key',
            'ninja.whatsapp.evolution.instance' => 'InvoiceNinja',
        ]);

        $this->client->phone = '11987654321';
        $this->client->save();

        $this->createAsaasGateway();
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

        $this->gateway = $cg;
    }

    public function test_request_nota_fiscal_for_payment(): void
    {
        Http::fake([
            'api.asaas.com/v3/customers*' => Http::response(['id' => 'cus_123'], 200),
            'api.asaas.com/v3/invoices' => Http::response([
                'id' => 'inv_123',
                'status' => 'SCHEDULED',
                'payment' => 'pay_123',
                'value' => 100.0,
            ], 200),
        ]);

        $service = new AsaasNotaFiscalService($this->gateway);
        $response = $service->requestInvoiceForPayment($this->invoice, 'pay_123', 100.0, 'cus_123');

        $this->assertNotNull($response);
        $this->assertSame('inv_123', $response['id']);
        $this->assertSame('SCHEDULED', $response['status']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.asaas.com/v3/invoices'
                && $request->method() === 'POST'
                && $request['payment'] === 'pay_123';
        });
    }

    public function test_handle_invoice_authorized_webhook(): void
    {
        Http::fake([
            'evolution.test/*' => Http::response(['key' => ['id' => 'msg_123']], 200),
            'asaas.test/nfe.pdf' => Http::response('%PDF-1.4 fake pdf', 200),
        ]);

        $hash = new PaymentHash();
        $hash->company_id = $this->company->id;
        $hash->hash = 'test_external_ref';
        $hash->invoice_id = $this->invoice->id;
        $hash->data = (object) [
            'asaas_payment_id' => 'pay_123',
            'amount_with_fee' => 100.0,
        ];
        $hash->save();

        $driver = new AsaasPaymentDriver($this->gateway);

        $request = Request::create(
            '/payment_webhook/' . $this->company->company_key . '/' . $this->gateway->id,
            'POST',
            [
                'event' => 'INVOICE_AUTHORIZED',
                'invoice' => [
                    'id' => 'inv_123',
                    'payment' => 'pay_123',
                    'externalReference' => 'test_external_ref',
                    'status' => 'AUTHORIZED',
                    'pdfUrl' => 'https://asaas.test/nfe.pdf',
                    'xmlUrl' => 'https://asaas.test/nfe.xml',
                    'number' => '456',
                    'validationCode' => 'VAL-123',
                    'value' => 100.0,
                ],
            ]
        );
        $request->headers->set('asaas-access-token', 'wh-token');

        $response = $driver->processWebhookRequest($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('ok', $data['status']);
        $this->assertTrue($data['result']['success']);
        $this->assertSame('456', $data['result']['nf_number']);
    }

    public function test_payment_confirmed_triggers_nota_fiscal_request(): void
    {
        Http::fake([
            'api.asaas.com/v3/customers*' => Http::response(['id' => 'cus_123'], 200),
            'api.asaas.com/v3/invoices' => Http::response([
                'id' => 'inv_999',
                'status' => 'SCHEDULED',
                'payment' => 'pay_test_456',
            ], 200),
        ]);

        $hash = new PaymentHash();
        $hash->company_id = $this->company->id;
        $hash->hash = 'test_hash_456';
        $hash->invoice_id = $this->invoice->id;
        $hash->data = (object) [
            'asaas_payment_id' => 'pay_test_456',
            'amount_with_fee' => 150.0,
        ];
        $hash->save();

        $driver = new AsaasPaymentDriver($this->gateway);

        $request = Request::create(
            '/payment_webhook/' . $this->company->company_key . '/' . $this->gateway->id,
            'POST',
            [
                'event' => 'PAYMENT_RECEIVED',
                'payment' => [
                    'id' => 'pay_test_456',
                    'customer' => 'cus_123',
                    'value' => 150.0,
                    'billingType' => 'PIX',
                    'externalReference' => 'test_hash_456',
                ],
            ]
        );
        $request->headers->set('asaas-access-token', 'wh-token');

        $response = $driver->processWebhookRequest($request);

        $this->assertSame(200, $response->getStatusCode());

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.asaas.com/v3/invoices'
                && $request->method() === 'POST'
                && $request['payment'] === 'pay_test_456';
        });
    }

    public function test_emit_nota_fiscal_manually_service(): void
    {
        Http::fake([
            'api.asaas.com/v3/customers*' => Http::response(['id' => 'cus_123'], 200),
            'api.asaas.com/v3/invoices' => Http::response([
                'id' => 'inv_man_123',
                'status' => 'SCHEDULED',
                'payment' => 'pay_man_123',
                'value' => 100.0,
            ], 200),
        ]);

        $hash = new PaymentHash();
        $hash->company_id = $this->company->id;
        $hash->hash = 'test_hash_man';
        $hash->invoice_id = $this->invoice->id;
        $hash->data = (object) [
            'asaas_payment_id' => 'pay_man_123',
            'amount_with_fee' => 100.0,
        ];
        $hash->save();

        $service = AsaasNotaFiscalService::forInvoice($this->invoice);
        $this->assertNotNull($service);

        $result = $service->emitNotaFiscalManually($this->invoice);
        $this->assertTrue($result['success']);
        $this->assertSame('inv_man_123', $result['data']['id']);
    }

    public function test_emit_nota_fiscal_controller_action(): void
    {
        Http::fake([
            'api.asaas.com/v3/customers*' => Http::response(['id' => 'cus_123'], 200),
            'api.asaas.com/v3/invoices' => Http::response([
                'id' => 'inv_ctrl_123',
                'status' => 'SCHEDULED',
                'payment' => 'pay_ctrl_123',
                'value' => 100.0,
            ], 200),
        ]);

        $hash = new PaymentHash();
        $hash->company_id = $this->company->id;
        $hash->hash = 'test_hash_ctrl';
        $hash->invoice_id = $this->invoice->id;
        $hash->data = (object) [
            'asaas_payment_id' => 'pay_ctrl_123',
            'amount_with_fee' => 100.0,
        ];
        $hash->save();

        $controller = new \App\Http\Controllers\InvoiceController(new \App\Repositories\InvoiceRepository());
        $response = $controller->emitAsaasNotaFiscal($this->invoice);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('inv_ctrl_123', $data['data']['id']);
    }

    public function test_automatic_nota_fiscal_skipped_when_user_has_emission_disabled(): void
    {
        $this->user->emit_nota_fiscal = false;
        $this->user->save();

        $this->invoice->assigned_user_id = $this->user->id;
        $this->invoice->save();

        $service = new AsaasNotaFiscalService($this->gateway);
        $response = $service->requestInvoiceForPayment($this->invoice, 'pay_skip_123', 100.0, 'cus_123');

        $this->assertNull($response);
    }
}
