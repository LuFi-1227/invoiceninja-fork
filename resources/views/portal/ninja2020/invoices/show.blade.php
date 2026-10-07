@extends('portal.ninja2020.layout.app')
@section('meta_title', ctrans('texts.view_invoice'))

@push('head')
    <meta name="show-invoice-terms" content="{{ $settings->show_accept_invoice_terms ? true : false }}">
    <meta name="require-invoice-signature" content="{{ $requires_signature }}">
    @include('portal.ninja2020.components.no-cache')
    <script src="{{ asset('vendor/signature_pad@2.3.2/signature_pad.min.js') }}"></script>

@endpush

@section('header')
    @if($errors->any())
        <div class="alert alert-failure mb-4">
            @foreach($errors->all() as $error)
            <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif
@endsection

@section('body')

    @if($invoice->isPayable() && $client->getSetting('custom_message_unpaid_invoice'))
        @component('portal.ninja2020.components.message')
            <pre>{{ $client->getSetting('custom_message_unpaid_invoice') }}</pre>
        @endcomponent
    @elseif($invoice->status_id === 4 && $client->getSetting('custom_message_paid_invoice'))
        @component('portal.ninja2020.components.message')
            <pre>{{ $client->getSetting('custom_message_paid_invoice') }}</pre>
        @endcomponent
    @endif

    @if($invoice->isPayable())
        <form action="{{ ($settings->client_portal_allow_under_payment || $settings->client_portal_allow_over_payment) ? route('client.invoices.bulk') : route('client.payments.process') }}" method="post" id="payment-form">
            @csrf
            <input type="hidden" name="invoices[]" value="{{ $invoice->hashed_id }}">
            <input type="hidden" name="action" value="payment">

            <input type="hidden" name="company_gateway_id" id="company_gateway_id">
            <input type="hidden" name="payment_method_id" id="payment_method_id">
            <input type="hidden" name="signature">
            <input type="hidden" name="hash" value="{{ $hash }}">
            <input type="hidden" name="payable_invoices[0][amount]" value="{{ $invoice->partial > 0 ?  \App\Utils\Number::formatValue($invoice->partial, $invoice->client->currency()) : \App\Utils\Number::formatValue($invoice->balance, $invoice->client->currency()) }}">
            <input type="hidden" name="payable_invoices[0][invoice_id]" value="{{ $invoice->hashed_id }}">
            <input type="hidden" name="contact_first_name" value="{{ auth()->guard('contact')->user()->first_name }}">
            <input type="hidden" name="contact_last_name" value="{{ auth()->guard('contact')->user()->last_name }}">
            <input type="hidden" name="contact_email" value="{{ auth()->guard('contact')->user()->email }}">
            <input type="hidden" name="client_city" value="{{ auth()->guard('contact')->user()->client->city }}">
            <input type="hidden" name="client_postal_code" value="{{ auth()->guard('contact')->user()->client->postal_code }}">
            <input type="hidden" name="docuninja_active" value="{{ $docuninja_active ? 'true' : 'false' }}">
            <div class="bg-white shadow sm:rounded-lg mb-4" translate>
                <div class="px-4 py-5 sm:p-6">
                    <div class="sm:flex sm:items-start sm:justify-between">
                        <div>
                            <h3 class="text-lg leading-6 font-medium text-gray-900">
                                {{ ctrans('texts.invoice_number_placeholder', ['invoice' => $invoice->number])}}
                                - {{ ctrans('texts.unpaid') }}
                            </h3>
                        </div>
                        <div class="mt-5 sm:mt-0 sm:ml-6 flex items-center justify-end space-x-2">
                            @php
                                $whatsappText = rawurlencode("Olá! Segue a fatura " . ($invoice->number ?? '') . " no valor de " . \App\Utils\Number::formatValue($invoice->balance, $invoice->client->currency()) . ".\n\nAcesse para visualizar e pagar via Pix ou Boleto:\n" . url()->current());
                            @endphp
                            <a href="https://api.whatsapp.com/send?text={{ $whatsappText }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center px-3 py-2 border border-emerald-500 text-xs font-semibold rounded-md text-emerald-700 bg-emerald-50 hover:bg-emerald-100 transition shadow-sm mr-2" title="Compartilhar fatura no WhatsApp">
                                <svg class="w-4 h-4 mr-1.5 fill-current text-emerald-600" viewBox="0 0 24 24">
                                    <path d="M12.031 6.172c-3.181 0-5.767 2.586-5.768 5.766-.001 1.298.38 2.27 1.019 3.287l-.582 2.128 2.182-.573c.978.58 1.911.928 3.145.929 3.178 0 5.767-2.587 5.768-5.766.001-3.187-2.575-5.77-5.764-5.771zm3.392 8.244c-.144.405-.837.774-1.17.824-.299.045-.677.063-1.092-.069-.252-.08-.575-.187-.988-.365-1.739-.751-2.874-2.502-2.961-2.617-.087-.116-.708-.94-.708-1.793s.448-1.273.607-1.446c.159-.173.346-.217.462-.217l.332.006c.106.005.249-.04.39.298.144.347.491 1.2.534 1.287.043.087.072.188.014.303-.058.116-.087.188-.173.289l-.26.303c-.087.087-.177.182-.076.356.101.174.449.741.964 1.2.663.591 1.221.774 1.394.861.173.086.275.072.376-.044.101-.116.433-.506.549-.68.116-.173.231-.144.39-.086s1.011.477 1.184.564.289.13.332.202c.043.073.043.42-.101.825zM12 2C6.477 2 2 6.477 2 12c0 1.891.524 3.66 1.434 5.176L2 22l4.954-1.399C8.423 21.493 10.155 22 12 22c5.523 0 10-4.477 10-10S17.523 2 12 2z"/>
                                </svg>
                                WhatsApp
                            </a>
                            <div class="inline-flex rounded-md shadow-sm">
                                <input type="hidden" name="invoices[]" value="{{ $invoice->hashed_id }}">
                                <input type="hidden" name="action" value="payment">

                                @if($settings->client_portal_allow_under_payment || $settings->client_portal_allow_over_payment)
                                    <button class="button button-primary bg-primary">{{ ctrans('texts.pay_now') }}</button>
                                @else
                                    @livewire('pay-now-dropdown', ['total' => $invoice->getPayableAmount(), 'company_id' => $company->id, 'db' => $company->db])
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    @else
        <div class="bg-white shadow sm:rounded-lg mb-4">
            <div class="px-4 py-5 sm:p-6">
                <div class="sm:flex sm:items-start sm:justify-between">
                    <div class="flex justify-between items-center w-full">
                        <h3 class="text-lg leading-6 font-medium text-gray-900">
                            {{ ctrans('texts.invoice_number_placeholder', ['invoice' => $invoice->number])}}
                            - {{ \App\Models\Invoice::stringStatus($invoice->status_id) }}
                        </h3>
                        @if($invoice->status_id == \App\Models\Invoice::STATUS_PAID && $invoice->payments()->exists())
                        <span class="ml-4"><a class="button-link text-primary" href="{{ route('client.payments.show', $invoice->payments->first()->hashed_id) }}">{{ ctrans('texts.view_payment') }}</a></span>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif

    @include('portal.ninja2020.components.entity-documents', ['entity' => $invoice])
    @livewire('pdf-slot', ['entity_type' => 'invoice', 'entity_key' => $invoice->hashed_id, 'invitation_key' => $invitation->key ?? null, 'db' => $invoice->company->db])

@endsection

@section('footer')
    @include('portal.ninja2020.invoices.includes.required-fields')
    @include('portal.ninja2020.invoices.includes.signature')
    @include('portal.ninja2020.invoices.includes.terms', ['entities' => [$invoice], 'variables' => $variables, 'entity_type' => ctrans('texts.invoice')])
@endsection

@push('head')
    @vite('resources/js/clients/invoices/payment.js')

    <script type="text/javascript">

        document.addEventListener('DOMContentLoaded', () => {

            @if($_key)
                window.history.pushState({}, "", "{{ url("client/invoice/{$_key}") }}");
            @endif

        });

    </script>
@endpush
