<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Pareamento do WhatsApp — {{ $company->present()->name() ?? config('app.name', 'Gestoremio') }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .pulse-slow { animation: pulse 2.5s cubic-bezier(0.4, 0, 0.6, 1) infinite; }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }
    </style>
</head>
<body class="bg-slate-100 min-h-screen text-slate-800 flex flex-col justify-between">

    <!-- Top Header -->
    <header class="bg-white border-b border-slate-200 sticky top-0 z-30">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 py-4 flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-xl bg-emerald-500 text-white flex items-center justify-center shadow-sm">
                    <svg class="w-6 h-6 fill-current" viewBox="0 0 24 24">
                        <path d="M12.031 6.172c-3.181 0-5.767 2.586-5.768 5.766-.001 1.298.38 2.27 1.019 3.287l-.582 2.128 2.182-.573c.978.58 1.911.928 3.145.929 3.178 0 5.767-2.587 5.768-5.766.001-3.187-2.575-5.77-5.764-5.771zm3.392 8.244c-.144.405-.837.774-1.17.824-.299.045-.677.063-1.092-.069-.252-.08-.575-.187-.988-.365-1.739-.751-2.874-2.502-2.961-2.617-.087-.116-.708-.94-.708-1.793s.448-1.273.607-1.446c.159-.173.346-.217.462-.217l.332.006c.106.005.249-.04.39.298.144.347.491 1.2.534 1.287.043.087.072.188.014.303-.058.116-.087.188-.173.289l-.26.303c-.087.087-.177.182-.076.356.101.174.449.741.964 1.2.663.591 1.221.774 1.394.861.173.086.275.072.376-.044.101-.116.433-.506.549-.68.116-.173.231-.144.39-.086s1.011.477 1.184.564.289.13.332.202c.043.073.043.42-.101.825zM12 2C6.477 2 2 6.477 2 12c0 1.891.524 3.66 1.434 5.176L2 22l4.954-1.399C8.423 21.493 10.155 22 12 22c5.523 0 10-4.477 10-10S17.523 2 12 2z"/>
                    </svg>
                </div>
                <div>
                    <h1 class="text-lg font-bold text-slate-900 leading-tight">Conexão WhatsApp</h1>
                    <p class="text-xs text-slate-500">{{ $company->present()->name() ?? $company->name ?? config('app.name', 'Gestoremio') }}</p>
                </div>
            </div>

            <!-- Status Indicator -->
            <div id="status-badge" class="flex items-center space-x-2 px-3 py-1.5 rounded-full text-xs font-semibold
                {{ ($status['connected'] ?? false) ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">
                <span class="w-2.5 h-2.5 rounded-full {{ ($status['connected'] ?? false) ? 'bg-emerald-500' : 'bg-amber-500 animate-ping' }}"></span>
                <span id="status-text">{{ ($status['connected'] ?? false) ? 'Conectado' : 'Aguardando Pareamento' }}</span>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <main class="max-w-4xl mx-auto px-4 sm:px-6 py-8 flex-1 w-full">

        <!-- Error/Notice Banner (if needed) -->
        <div id="alert-banner" class="hidden mb-6 p-4 rounded-xl text-sm font-medium transition-all duration-300"></div>

        <div class="grid grid-cols-1 md:grid-cols-12 gap-8 items-start">

            <!-- Left Column: Instructions and State -->
            <div class="md:col-span-7 space-y-6">

                <!-- Connection Card -->
                <div class="bg-white rounded-2xl p-6 shadow-sm border border-slate-200">
                    <h2 class="text-xl font-bold text-slate-900 mb-2">Conecte seu WhatsApp</h2>
                    <p class="text-slate-600 text-sm mb-6">
                        Com o WhatsApp pareado, suas faturas, recibos e lembretes com código Pix e linha digitável do Asaas serão enviados instantaneamente para seus clientes.
                    </p>

                    <!-- Steps List -->
                    <div class="space-y-4">
                        <div class="flex items-start space-x-3.5">
                            <div class="w-7 h-7 rounded-full bg-slate-100 text-slate-700 font-bold text-xs flex items-center justify-center shrink-0 border border-slate-200">1</div>
                            <p class="text-sm text-slate-700 pt-0.5">Abra o aplicativo do <strong>WhatsApp</strong> no seu celular.</p>
                        </div>
                        <div class="flex items-start space-x-3.5">
                            <div class="w-7 h-7 rounded-full bg-slate-100 text-slate-700 font-bold text-xs flex items-center justify-center shrink-0 border border-slate-200">2</div>
                            <p class="text-sm text-slate-700 pt-0.5">Acesse o menu <strong>Mais opções (⋮)</strong> no Android ou <strong>Configurações</strong> no iPhone.</p>
                        </div>
                        <div class="flex items-start space-x-3.5">
                            <div class="w-7 h-7 rounded-full bg-slate-100 text-slate-700 font-bold text-xs flex items-center justify-center shrink-0 border border-slate-200">3</div>
                            <p class="text-sm text-slate-700 pt-0.5">Toque em <strong>Aparelhos conectados</strong> e depois em <strong>Conectar um aparelho</strong>.</p>
                        </div>
                        <div class="flex items-start space-x-3.5">
                            <div class="w-7 h-7 rounded-full bg-slate-100 text-slate-700 font-bold text-xs flex items-center justify-center shrink-0 border border-slate-200">4</div>
                            <p class="text-sm text-slate-700 pt-0.5">Aponte a câmera do seu celular para o <strong>QR Code</strong> ao lado.</p>
                        </div>
                    </div>

                    <!-- Instance Meta info -->
                    <div class="mt-6 pt-5 border-t border-slate-100 flex flex-wrap items-center justify-between text-xs text-slate-500">
                        <div>
                            <span>Instância: </span>
                            <span class="font-mono font-medium text-slate-800">{{ $config['evolution_instance'] ?? 'invoiceninja' }}</span>
                        </div>
                        <div class="mt-2 sm:mt-0">
                            <span>Provedor: </span>
                            <span class="font-medium text-slate-800 uppercase">{{ $config['provider'] ?? 'evolution' }} API</span>
                        </div>
                    </div>
                </div>

                <!-- Test Message Sender Card -->
                <div class="bg-white rounded-2xl p-6 shadow-sm border border-slate-200">
                    <h3 class="text-base font-bold text-slate-900 mb-1 flex items-center">
                        <svg class="w-4 h-4 text-emerald-600 mr-2" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path>
                        </svg>
                        Testar Envio de Mensagem
                    </h3>
                    <p class="text-xs text-slate-500 mb-4">Envie uma mensagem de teste para verificar se a conexão está funcionando em tempo real.</p>

                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Telefone com DDD (apenas números)</label>
                            <input type="text" id="test-phone" placeholder="Ex: 11999998888" class="w-full text-sm px-3.5 py-2.5 rounded-lg border border-slate-300 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 bg-slate-50 focus:bg-white transition-all">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Mensagem de teste</label>
                            <input type="text" id="test-message" value="Olá! Esta é uma mensagem de teste da sua plataforma de faturamento." class="w-full text-sm px-3.5 py-2.5 rounded-lg border border-slate-300 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 bg-slate-50 focus:bg-white transition-all">
                        </div>
                        <button type="button" id="btn-send-test" onclick="sendTestMessage()" class="w-full sm:w-auto inline-flex items-center justify-center px-4 py-2.5 bg-slate-900 hover:bg-slate-800 text-white text-xs font-semibold rounded-lg shadow transition-all duration-150">
                            <span id="send-test-label">Enviar Teste Agora</span>
                            <svg id="send-test-spinner" class="hidden ml-2 w-4 h-4 text-white animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                            </svg>
                        </button>
                    </div>
                </div>

            </div>

            <!-- Right Column: Interactive QR Code and Action Buttons -->
            <div class="md:col-span-5">
                <div class="bg-white rounded-2xl p-6 shadow-sm border border-slate-200 text-center flex flex-col items-center">

                    <!-- State 1: Connected View -->
                    <div id="view-connected" class="{{ ($status['connected'] ?? false) ? '' : 'hidden' }} py-8 w-full">
                        <div class="w-20 h-20 mx-auto rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center mb-4">
                            <svg class="w-10 h-10" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path>
                            </svg>
                        </div>
                        <h3 class="text-lg font-bold text-slate-900 mb-1">WhatsApp Conectado!</h3>
                        <p class="text-xs text-slate-500 max-w-xs mx-auto mb-6">Sua instância está ativa e pronta para enviar cobranças, links Pix e faturas em PDF.</p>

                        <div class="space-y-2.5 w-full">
                            <button type="button" onclick="loadStatus()" class="w-full py-2.5 px-4 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold rounded-xl transition">
                                Verificar Status Novamente
                            </button>
                            <button type="button" onclick="disconnectWhatsApp()" class="w-full py-2.5 px-4 bg-red-50 hover:bg-red-100 text-red-600 text-xs font-semibold rounded-xl transition">
                                Desconectar este Aparelho
                            </button>
                        </div>
                    </div>

                    <!-- State 2: QR Code View -->
                    <div id="view-qrcode" class="{{ ($status['connected'] ?? false) ? 'hidden' : '' }} w-full flex flex-col items-center">
                        <div class="relative w-64 h-64 bg-slate-50 rounded-2xl border-2 border-dashed border-slate-300 flex items-center justify-center overflow-hidden mb-4 shadow-inner">

                            <!-- Loading Spinner inside QR box -->
                            <div id="qr-loading" class="flex flex-col items-center space-y-2 text-slate-400">
                                <svg class="w-8 h-8 animate-spin text-emerald-500" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                </svg>
                                <span class="text-xs font-medium">Carregando QR Code...</span>
                            </div>

                            <!-- QR Code Image -->
                            <img id="qr-image" src="" alt="QR Code WhatsApp" class="hidden w-full h-full object-contain p-2">
                        </div>

                        <!-- Pairing Code Alternative -->
                        <div id="pairing-code-container" class="hidden mb-4 p-3 bg-slate-50 rounded-xl border border-slate-200 w-full text-center">
                            <span class="text-xs text-slate-500 block mb-1">Código de Pareamento alternativo:</span>
                            <span id="pairing-code-text" class="text-base font-mono font-bold tracking-widest text-slate-900 select-all"></span>
                        </div>

                        <!-- Reload button -->
                        <button type="button" id="btn-refresh-qr" onclick="loadQrCode()" class="w-full py-2.5 px-4 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold rounded-xl shadow transition flex items-center justify-center space-x-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                            </svg>
                            <span id="refresh-qr-label">Gerar Novo QR Code</span>
                        </button>

                        <p class="text-[11px] text-slate-400 mt-3 flex items-center">
                            <span class="w-2 h-2 rounded-full bg-emerald-500 inline-block mr-1.5 pulse-slow"></span>
                            Atualizando conexão automaticamente...
                        </p>
                    </div>

                </div>
            </div>

        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-white border-t border-slate-200 py-4 text-center text-xs text-slate-400">
        <p>{{ config('app.name', 'Gestoremio') }} &copy; {{ date('Y') }} &bull; Integração WhatsApp &bull; Criptografia AES-256 ativa</p>
    </footer>

    <!-- Interactive Script -->
    <script>
        const token = "{{ $token ?? '' }}";
        const companyKey = "{{ $company_key ?? '' }}";
        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

        let isPolling = true;
        let pollTimer = null;

        function showAlert(message, type = 'success') {
            const banner = document.getElementById('alert-banner');
            banner.className = `mb-6 p-4 rounded-xl text-sm font-medium transition-all duration-300 ${
                type === 'success' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-red-50 text-red-800 border border-red-200'
            }`;
            banner.innerHTML = message;
            banner.classList.remove('hidden');
            setTimeout(() => {
                banner.classList.add('hidden');
            }, 6000);
        }

        function setStatus(connected, stateName = '') {
            const badge = document.getElementById('status-badge');
            const statusText = document.getElementById('status-text');
            const viewConnected = document.getElementById('view-connected');
            const viewQrcode = document.getElementById('view-qrcode');

            if (connected) {
                badge.className = 'flex items-center space-x-2 px-3 py-1.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800';
                badge.querySelector('span').className = 'w-2.5 h-2.5 rounded-full bg-emerald-500';
                statusText.innerText = 'Conectado';
                viewConnected.classList.remove('hidden');
                viewQrcode.classList.add('hidden');
            } else {
                badge.className = 'flex items-center space-x-2 px-3 py-1.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-800';
                badge.querySelector('span').className = 'w-2.5 h-2.5 rounded-full bg-amber-500 animate-ping';
                statusText.innerText = stateName ? `Estado: ${stateName}` : 'Aguardando Pareamento';
                viewConnected.classList.add('hidden');
                viewQrcode.classList.remove('hidden');
            }
        }

        async function loadQrCode() {
            const qrLoading = document.getElementById('qr-loading');
            const qrImage = document.getElementById('qr-image');
            const pairingBox = document.getElementById('pairing-code-container');
            const pairingText = document.getElementById('pairing-code-text');
            const refreshLabel = document.getElementById('refresh-qr-label');

            qrLoading.classList.remove('hidden');
            qrImage.classList.add('hidden');
            refreshLabel.innerText = 'Carregando...';

            try {
                const url = new URL('/api/v1/whatsapp/qrcode', window.location.origin);
                if (token) url.searchParams.set('token', token);
                if (companyKey) url.searchParams.set('company_key', companyKey);

                const response = await fetch(url.href, {
                    headers: { 'Accept': 'application/json' }
                });
                const data = await response.json();

                if (data.connected) {
                    setStatus(true);
                    showAlert('Instância já está conectada ao WhatsApp!', 'success');
                    return;
                }

                if (data.qrcode) {
                    qrImage.src = data.qrcode.startsWith('data:') ? data.qrcode : `data:image/png;base64,${data.qrcode}`;
                    qrImage.classList.remove('hidden');
                    qrLoading.classList.add('hidden');

                    if (data.pairing_code) {
                        pairingText.innerText = data.pairing_code;
                        pairingBox.classList.remove('hidden');
                    } else {
                        pairingBox.classList.add('hidden');
                    }
                } else if (data.error) {
                    showAlert(data.error, 'error');
                }
            } catch (err) {
                console.error(err);
                showAlert('Erro ao obter QR Code da Evolution API.', 'error');
            } finally {
                refreshLabel.innerText = 'Gerar Novo QR Code';
            }
        }

        async function loadStatus() {
            try {
                const url = new URL('/api/v1/whatsapp/status', window.location.origin);
                if (token) url.searchParams.set('token', token);
                if (companyKey) url.searchParams.set('company_key', companyKey);

                const response = await fetch(url.href, {
                    headers: { 'Accept': 'application/json' }
                });
                const data = await response.json();

                if (data.connected) {
                    setStatus(true);
                } else {
                    setStatus(false, data.state);
                }
            } catch (err) {
                console.warn('Erro ao checar status:', err);
            }
        }

        async function disconnectWhatsApp() {
            if (!confirm('Deseja realmente desconectar este WhatsApp? Você precisará ler o QR Code novamente para reconectar.')) {
                return;
            }

            try {
                const url = new URL('/api/v1/whatsapp/disconnect', window.location.origin);
                if (token) url.searchParams.set('token', token);
                if (companyKey) url.searchParams.set('company_key', companyKey);

                const response = await fetch(url.href, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    }
                });
                const data = await response.json();

                if (data.success) {
                    setStatus(false);
                    showAlert('WhatsApp desconectado com sucesso.', 'success');
                    loadQrCode();
                } else {
                    showAlert(data.error || 'Falha ao desconectar.', 'error');
                }
            } catch (err) {
                showAlert('Erro ao desconectar instância: ' + err.message, 'error');
            }
        }

        async function sendTestMessage() {
            const phone = document.getElementById('test-phone').value.trim();
            const message = document.getElementById('test-message').value.trim();
            const btn = document.getElementById('btn-send-test');
            const label = document.getElementById('send-test-label');
            const spinner = document.getElementById('send-test-spinner');

            if (!phone) {
                showAlert('Por favor, informe o número de telefone com DDD.', 'error');
                return;
            }

            btn.disabled = true;
            label.innerText = 'Enviando...';
            spinner.classList.remove('hidden');

            try {
                const url = new URL('/api/v1/whatsapp/send_test', window.location.origin);
                const response = await fetch(url.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({ phone, message })
                });
                const data = await response.json();

                if (data.success) {
                    showAlert(`Mensagem de teste enviada com sucesso para ${phone}!`, 'success');
                } else {
                    showAlert(`Falha no envio: ${data.error || 'Erro desconhecido.'}`, 'error');
                }
            } catch (err) {
                showAlert('Erro de conexão ao enviar teste: ' + err.message, 'error');
            } finally {
                btn.disabled = false;
                label.innerText = 'Enviar Teste Agora';
                spinner.classList.add('hidden');
            }
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', () => {
            const isInitialConnected = {{ ($status['connected'] ?? false) ? 'true' : 'false' }};
            if (!isInitialConnected) {
                loadQrCode();
            }

            // Real-time polling every 3.5s
            pollTimer = setInterval(async () => {
                if (isPolling) {
                    await loadStatus();
                }
            }, 3500);
        });
    </script>
</body>
</html>
