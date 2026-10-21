<?php

namespace Tests\Feature\RecurringInvoice;

use App\Jobs\Entity\EmailEntity;
use App\Jobs\RecurringInvoice\SendRecurring;
use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\MockAccountData;
use Tests\TestCase;

class UserContactMethodRecurringTest extends TestCase
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
            'ninja.whatsapp.evolution.api_url' => 'https://evolution.test',
            'ninja.whatsapp.evolution.api_key' => 'test-key',
            'ninja.whatsapp.evolution.instance' => 'InvoiceNinja',
        ]);

        $this->client->phone = '11987654321';
        $this->client->save();
    }

    public function test_user_contact_method_helpers(): void
    {
        $user = new User();

        // Empty / null should return false
        $user->contact_method = null;
        $this->assertFalse($user->hasContactMethod());
        $this->assertFalse($user->hasWhatsAppContact());
        $this->assertFalse($user->hasEmailContact());

        $user->contact_method = '';
        $this->assertFalse($user->hasContactMethod());
        $this->assertFalse($user->hasWhatsAppContact());
        $this->assertFalse($user->hasEmailContact());

        $user->contact_method = 'invalid_option';
        $this->assertFalse($user->hasContactMethod());
        $this->assertFalse($user->hasWhatsAppContact());
        $this->assertFalse($user->hasEmailContact());

        $user->contact_method = 'whatsapp_and_email';
        $this->assertTrue($user->hasContactMethod());
        $this->assertTrue($user->hasWhatsAppContact());
        $this->assertTrue($user->hasEmailContact());

        $user->contact_method = 'whatsapp';
        $this->assertTrue($user->hasContactMethod());
        $this->assertTrue($user->hasWhatsAppContact());
        $this->assertFalse($user->hasEmailContact());

        $user->contact_method = 'email';
        $this->assertTrue($user->hasContactMethod());
        $this->assertFalse($user->hasWhatsAppContact());
        $this->assertTrue($user->hasEmailContact());

        // Test forma_de_contato alias
        $user->forma_de_contato = 'whatsapp';
        $this->assertEquals('whatsapp', $user->contact_method);
        $this->assertEquals('whatsapp', $user->forma_de_contato);
    }

    public function test_user_transformer_outputs_contact_method_fields(): void
    {
        $user = new User();
        $user->first_name = 'Test';
        $user->last_name = 'User';
        $user->email = 'test@example.com';
        $user->contact_method = 'whatsapp';

        $transformer = new \App\Transformers\UserTransformer();
        $transformed = $transformer->transform($user);

        $this->assertEquals('whatsapp', $transformed['contact_method']);
        $this->assertEquals('whatsapp', $transformed['forma_de_contato']);
        $this->assertTrue($transformed['has_contact_method']);

        $user->contact_method = null;
        $transformedEmpty = $transformer->transform($user);
        $this->assertEquals('', $transformedEmpty['contact_method']);
        $this->assertFalse($transformedEmpty['has_contact_method']);
    }

    public function test_user_emit_nota_fiscal(): void
    {
        $user = new User();
        $this->assertTrue($user->shouldEmitNotaFiscal());

        $user->emit_nota_fiscal = false;
        $this->assertFalse($user->shouldEmitNotaFiscal());

        $user->emit_nota_fiscal = true;
        $this->assertTrue($user->shouldEmitNotaFiscal());

        $transformer = new \App\Transformers\UserTransformer();
        $transformed = $transformer->transform($user);
        $this->assertTrue($transformed['emit_nota_fiscal']);

        $user->emit_nota_fiscal = false;
        $transformedDisabled = $transformer->transform($user);
        $this->assertFalse($transformedDisabled['emit_nota_fiscal']);
    }
}
