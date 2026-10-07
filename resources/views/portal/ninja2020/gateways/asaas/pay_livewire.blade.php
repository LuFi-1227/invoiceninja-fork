<div id="asaas-payment-wrapper">
    <div class="rounded-lg border bg-card text-card-foreground shadow-sm overflow-hidden py-5 bg-white sm:gap-4"
        id="asaas-payment">

        <form action="{{ route('client.payments.response') }}" method="post" id="server-response">
            @csrf
            <input type="hidden" name="gateway_response">
            <input type="hidden" name="company_gateway_id" value="{{ $gateway->getCompanyGatewayId() }}">
            <input type="hidden" name="payment_method_id" value="{{ $payment_method_id }}">
            <input type="hidden" name="payment_hash" value="{{ $payment_hash }}">
            <input type="hidden" name="token">
        </form>

        <div style="position: relative;">
            @include('portal.ninja2020.gateways.includes.payment_details')
            @if(!empty($company_logo))
                <img src="{{ $company_logo }}" alt="{{ $company_name }}" style="position: absolute; top: 1.25rem; right: 1.5rem; max-height: 38px; object-fit: contain;">
            @endif
        </div>

        {{-- Payment Methods Tab Switcher --}}
        <div class="px-4 py-3 sm:px-6 border-b border-gray-200">
            <div class="flex border-b border-gray-200" id="asaas-tabs">
                <button type="button" onclick="switchAsaasTab('pix')" id="tab-btn-pix"
                    class="py-2 px-4 text-sm font-semibold border-b-2 border-emerald-600 text-emerald-600 flex items-center gap-2 focus:outline-none transition-colors">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12.0003 3.00012L4.50024 10.5001L12.0003 18.0001L19.5002 10.5001L12.0003 3.00012ZM12.0003 5.82855L16.6718 10.5001L12.0003 15.1717L7.32867 10.5001L12.0003 5.82855Z"/>
                    </svg>
                    <span>Pix (Instantâneo)</span>
                </button>
                <button type="button" onclick="switchAsaasTab('boleto')" id="tab-btn-boleto"
                    class="py-2 px-4 text-sm font-semibold border-b-2 border-transparent text-gray-500 hover:text-gray-700 flex items-center gap-2 focus:outline-none transition-colors">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="2" y="4" width="20" height="16" rx="2"/>
                        <line x1="6" y1="8" x2="6" y2="16"/>
                        <line x1="10" y1="8" x2="10" y2="16"/>
                        <line x1="14" y1="8" x2="14" y2="16"/>
                        <line x1="18" y1="8" x2="18" y2="16"/>
                    </svg>
                    <span>Boleto Bancário</span>
                </button>
            </div>
        </div>

        {{-- TAB 1: PIX --}}
        <div id="asaas-tab-pix" class="px-4 py-5 sm:px-6">
            <div class="flex flex-col items-center text-center">
                <p class="text-sm text-gray-600 mb-3">
                    Abra o aplicativo do seu banco e escaneie o <strong>QR Code Pix</strong> abaixo ou copie o código <strong>Pix Copia e Cola</strong>.
                </p>

                @if(!empty($pix_qr_image))
                    <div class="p-3 bg-white border rounded-xl shadow-sm inline-block my-2">
                        <img src="data:image/png;base64,{{ $pix_qr_image }}" alt="QR Code Pix" style="width: 220px; height: 220px; display: block;">
                    </div>
                @endif

                @if(!empty($pix_payload))
                    <div class="w-full max-w-md mt-3">
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1 text-left">
                            Pix Copia e Cola
                        </label>
                        <div class="flex items-center gap-2">
                            <input type="text" id="pix-copy-input" readonly value="{{ $pix_payload }}"
                                class="w-full px-3 py-2 text-xs text-gray-700 bg-gray-50 border rounded-lg focus:outline-none font-mono select-all">
                            <button type="button" onclick="copyPixCode()" id="btn-copy-pix"
                                class="px-3 py-2 text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-700 rounded-lg whitespace-nowrap transition-colors flex items-center gap-1">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                <span>Copiar</span>
                            </button>
                        </div>
                    </div>
                @endif

                {{-- Status spinner --}}
                <div class="w-full max-w-md mt-6">
                    <div class="flex items-center justify-center gap-2 p-3 text-xs font-medium text-amber-800 bg-amber-50 border border-amber-200 rounded-lg" id="asaas-status-box">
                        <span class="inline-block w-4 h-4 border-2 border-amber-600 border-r-transparent rounded-full animate-spin" id="asaas-spinner"></span>
                        <span id="asaas-status-text">Aguardando pagamento... A confirmação é instantânea.</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- TAB 2: BOLETO BANCÁRIO --}}
        <div id="asaas-tab-boleto" class="px-4 py-5 sm:px-6 hidden">
            <div class="flex flex-col items-center text-center">
                <div class="w-full max-w-md bg-gray-50 border rounded-xl p-4 text-left mb-4">
                    <div class="flex justify-between items-center mb-3">
                        <span class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Vencimento</span>
                        <span class="text-sm font-bold text-gray-800">
                            {{ !empty($due_date) ? \Carbon\Carbon::parse($due_date)->format('d/m/Y') : '--/--/----' }}
                        </span>
                    </div>

                    @if(!empty($linha_digitavel))
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">
                            Linha Digitável (Código de Barras)
                        </label>
                        <div class="flex items-center gap-2 mb-2">
                            <input type="text" id="boleto-copy-input" readonly value="{{ $linha_digitavel }}"
                                class="w-full px-3 py-2 text-xs text-gray-800 bg-white border rounded-lg focus:outline-none font-mono select-all">
                            <button type="button" onclick="copyBoletoCode()" id="btn-copy-boleto"
                                class="px-3 py-2 text-xs font-semibold text-white bg-blue-600 hover:bg-blue-700 rounded-lg whitespace-nowrap transition-colors flex items-center gap-1">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                <span>Copiar</span>
                            </button>
                        </div>
                    @endif
                </div>

                @if(!empty($bank_slip_url))
                    <div class="my-3">
                        <a href="{{ $bank_slip_url }}" target="_blank" rel="noopener noreferrer"
                            class="inline-flex items-center gap-2 px-6 py-3 bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-sm rounded-lg shadow-sm transition-all transform hover:-translate-y-0.5">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                            <span>Visualizar / Baixar Boleto em PDF</span>
                        </a>
                    </div>
                @endif

                <p class="text-xs text-gray-500 mt-2 max-w-sm">
                    O boleto pode ser pago em qualquer agência bancária, internet banking ou casas lotéricas até a data de vencimento.
                </p>
            </div>
        </div>

        {{-- Toast Notification --}}
        <div id="asaas-toast" class="fixed bottom-5 right-5 bg-gray-900 text-white text-xs px-4 py-2 rounded-lg shadow-lg opacity-0 transition-opacity duration-300 pointer-events-none z-50">
            Código copiado com sucesso!
        </div>

    </div>

    @script
    <script>
    const paymentHash = @json($payment_hash);
    const statusUrl = @json($gateway->genericWebhookUrl()) + '?check_status=1&payment_hash=' + encodeURIComponent(paymentHash);
    let pollInterval = null;

    function switchAsaasTab(tab) {
        const pixTab = document.getElementById('asaas-tab-pix');
        const boletoTab = document.getElementById('asaas-tab-boleto');
        const pixBtn = document.getElementById('tab-btn-pix');
        const boletoBtn = document.getElementById('tab-btn-boleto');

        if (tab === 'pix') {
            pixTab.classList.remove('hidden');
            boletoTab.classList.add('hidden');
            pixBtn.className = 'py-2 px-4 text-sm font-semibold border-b-2 border-emerald-600 text-emerald-600 flex items-center gap-2 focus:outline-none transition-colors';
            boletoBtn.className = 'py-2 px-4 text-sm font-semibold border-b-2 border-transparent text-gray-500 hover:text-gray-700 flex items-center gap-2 focus:outline-none transition-colors';
        } else {
            boletoTab.classList.remove('hidden');
            pixTab.classList.add('hidden');
            boletoBtn.className = 'py-2 px-4 text-sm font-semibold border-b-2 border-blue-600 text-blue-600 flex items-center gap-2 focus:outline-none transition-colors';
            pixBtn.className = 'py-2 px-4 text-sm font-semibold border-b-2 border-transparent text-gray-500 hover:text-gray-700 flex items-center gap-2 focus:outline-none transition-colors';
        }
    }

    function showToast(message) {
        const toast = document.getElementById('asaas-toast');
        if (!toast) return;
        toast.textContent = message;
        toast.classList.remove('opacity-0');
        toast.classList.add('opacity-100');
        setTimeout(() => {
            toast.classList.remove('opacity-100');
            toast.classList.add('opacity-0');
        }, 3000);
    }

    function copyPixCode() {
        const input = document.getElementById('pix-copy-input');
        if (input) {
            navigator.clipboard.writeText(input.value).then(() => {
                showToast('Chave Pix Copia e Cola copiada!');
            });
        }
    }

    function copyBoletoCode() {
        const input = document.getElementById('boleto-copy-input');
        if (input) {
            navigator.clipboard.writeText(input.value).then(() => {
                showToast('Linha digitável copiada com sucesso!');
            });
        }
    }

    // Auto-poll status from server
    function checkStatus() {
        fetch(statusUrl, {
            headers: { 'Accept': 'application/json' }
        })
        .then(response => response.json())
        .then(data => {
            if (data && (data.status === 'CONFIRMED' || data.status === 'RECEIVED')) {
                clearInterval(pollInterval);
                const statusBox = document.getElementById('asaas-status-box');
                const spinnerText = document.getElementById('asaas-status-text');
                const spinner = document.getElementById('asaas-spinner');

                if (statusBox) {
                    statusBox.className = 'flex items-center justify-center gap-2 p-3 text-xs font-semibold text-emerald-800 bg-emerald-50 border border-emerald-300 rounded-lg';
                }
                if (spinner) spinner.style.display = 'none';
                if (spinnerText) spinnerText.textContent = 'Pagamento confirmado com sucesso! Redirecionando...';

                setTimeout(() => {
                    document.getElementById('server-response').submit();
                }, 1200);
            }
        })
        .catch(err => {
            console.error('Asaas checkStatus error:', err);
        });
    }

    pollInterval = setInterval(checkStatus, 3000);
    </script>
    @endscript
</div>
