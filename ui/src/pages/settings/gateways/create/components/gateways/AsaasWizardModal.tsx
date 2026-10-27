/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2026. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

import { useState } from 'react';
import {
  AlertCircle,
  ArrowLeft,
  ArrowRight,
  Check,
  CheckCircle,
  Copy,
  ExternalLink,
  Eye,
  EyeOff,
  FileText,
  Key,
  RefreshCw,
  Shield,
  Zap,
} from 'react-feather';
import { useTranslation } from 'react-i18next';
import { useColorScheme } from '$app/common/colors';
import { apiEndpoint, endpoint } from '$app/common/helpers';
import { request } from '$app/common/helpers/request';
import { toast } from '$app/common/helpers/toast/toast';
import { useAccentColor } from '$app/common/hooks/useAccentColor';
import { useCurrentCompany } from '$app/common/hooks/useCurrentCompany';
import { CompanyGateway } from '$app/common/interfaces/company-gateway';
import { Gateway } from '$app/common/interfaces/statics';
import { Button, InputField } from '$app/components/forms';
import Toggle from '$app/components/forms/Toggle';
import { Modal } from '$app/components/Modal';

interface Props {
  visible: boolean;
  onClose: () => void;
  gateway: Gateway;
  companyGateway: CompanyGateway;
  setCompanyGateway: React.Dispatch<
    React.SetStateAction<CompanyGateway | undefined>
  >;
  isGatewaySaved?: boolean;
}

export function AsaasWizardModal({
  visible,
  onClose,
  gateway,
  companyGateway,
  setCompanyGateway,
  isGatewaySaved,
}: Props) {
  const [t] = useTranslation();
  const colors = useColorScheme();
  const accentColor = useAccentColor();
  const company = useCurrentCompany();

  const [step, setStep] = useState<number>(1);
  const [showApiKey, setShowApiKey] = useState<boolean>(false);
  const [isCopiedUrl, setIsCopiedUrl] = useState<boolean>(false);
  const [isCopiedToken, setIsCopiedToken] = useState<boolean>(false);

  // Health check state
  const [isTesting, setIsTesting] = useState<boolean>(false);
  const [testResult, setTestResult] = useState<{
    status: 'idle' | 'success' | 'error';
    message: string;
  }>({ status: 'idle', message: '' });

  // Resolve config values
  const parsedConfig = companyGateway?.config
    ? JSON.parse(companyGateway.config)
    : {};
  const apiKey = parsedConfig.apiKey || '';
  const webhookToken = parsedConfig.webhookToken || '';
  const testMode = Boolean(parsedConfig.testMode);
  const emitNfeAutomatically = parsedConfig.emitNfeAutomatically ?? true;
  const municipalServiceCode = parsedConfig.municipalServiceCode || '';

  // Dynamic Webhook URL
  const companyKey = company?.company_key || '';
  const companyGatewayId = companyGateway?.id || '';
  const isSaved = Boolean(companyGatewayId && companyGatewayId.length > 0);
  const webhookUrl = isSaved
    ? `${apiEndpoint()}/payment_webhook/${companyKey}/${companyGatewayId}`
    : `${apiEndpoint()}/payment_webhook/${companyKey}/[SALVE_O_GATEWAY_PRIMEIRO]`;

  const updateConfigField = (field: string, value: string | boolean) => {
    setCompanyGateway((prev) => {
      if (!prev) return prev;
      const current = prev.config ? JSON.parse(prev.config) : {};
      return {
        ...prev,
        config: JSON.stringify({
          ...current,
          [field]: value,
        }),
      };
    });
  };

  const handleCopy = (text: string, type: 'url' | 'token') => {
    if (!text || text.includes('[SALVE_O_GATEWAY')) {
      toast.error('Salve o gateway antes de copiar a URL definitiva.');
      return;
    }
    navigator.clipboard.writeText(text);
    if (type === 'url') {
      setIsCopiedUrl(true);
      setTimeout(() => setIsCopiedUrl(false), 2000);
      toast.success('URL do webhook copiada para a área de transferência!');
    } else {
      setIsCopiedToken(true);
      setTimeout(() => setIsCopiedToken(false), 2000);
      toast.success('Token do webhook copiado para a área de transferência!');
    }
  };

  const handleGenerateSecureToken = () => {
    const chars =
      'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    let token = 'whsec_';
    const array = new Uint8Array(32);
    window.crypto.getRandomValues(array);
    for (let i = 0; i < 32; i++) {
      token += chars[array[i] % chars.length];
    }
    updateConfigField('webhookToken', token);
    toast.success('Novo token seguro gerado com sucesso!');
  };

  const handleTestConnection = () => {
    if (!companyGatewayId) {
      setTestResult({
        status: 'error',
        message: 'Salve as credenciais do gateway antes de testar a conexão.',
      });
      return;
    }

    if (!apiKey) {
      setTestResult({
        status: 'error',
        message: 'A Chave da API (apiKey) deve ser informada antes de testar.',
      });
      return;
    }

    setIsTesting(true);
    setTestResult({ status: 'idle', message: '' });

    request(
      'POST',
      endpoint('/api/v1/company_gateways/:id/test', {
        id: companyGatewayId,
      })
    )
      .then((response) => {
        const msg = response?.data?.message;
        if (msg === 'ok') {
          setTestResult({
            status: 'success',
            message:
              'Conexão com a API do Asaas estabelecida com sucesso! Credenciais e saldo validados.',
          });
        } else {
          setTestResult({
            status: 'error',
            message:
              typeof msg === 'string' && msg.length > 0
                ? msg
                : 'Não foi possível validar a conexão com o Asaas. Verifique a chave de API.',
          });
        }
      })
      .catch((error) => {
        setTestResult({
          status: 'error',
          message:
            error?.response?.data?.message ||
            'Erro ao conectar com o Asaas. Verifique sua chave de API e o modo Sandbox/Produção.',
        });
      })
      .finally(() => {
        setIsTesting(false);
      });
  };

  const asaasUrl = testMode
    ? 'https://sandbox.asaas.com'
    : 'https://www.asaas.com';

  const supportedEvents = [
    { name: 'PAYMENT_RECEIVED', label: 'Cobrança Recebida' },
    { name: 'PAYMENT_CONFIRMED', label: 'Cobrança Confirmada' },
    { name: 'PAYMENT_REFUNDED', label: 'Cobrança Estornada' },
    { name: 'PAYMENT_OVERDUE', label: 'Cobrança Vencida' },
    { name: 'INVOICE_AUTHORIZED', label: 'NFS-e Emitida' },
    { name: 'INVOICE_ERROR', label: 'Erro na NFS-e' },
  ];

  const steps = [
    { id: 1, title: 'Chave de API', icon: Key },
    { id: 2, title: 'Webhook Dinâmico', icon: Zap },
    { id: 3, title: 'Nota Fiscal (NFS-e)', icon: FileText },
    { id: 4, title: 'Validação e Teste', icon: Shield },
  ];

  return (
    <Modal
      title={
        <div className="flex items-center space-x-2">
          <div
            className="w-8 h-8 rounded-full flex items-center justify-center"
            style={{ backgroundColor: `${accentColor}20` }}
          >
            <Zap size={18} color={accentColor} />
          </div>
          <div>
            <h3 className="font-semibold text-base leading-tight">
              Assistente de Configuração do Asaas
            </h3>
            <p className="text-xs text-gray-500">
              Passo a passo para integrar Pix, Boleto e Webhooks automáticos
            </p>
          </div>
        </div>
      }
      visible={visible}
      onClose={onClose}
      size="large"
    >
      <div className="flex flex-col space-y-6 pb-2">
        {/* Stepper Header */}
        <div className="border-b pb-4" style={{ borderColor: colors.$20 }}>
          <div className="grid grid-cols-4 gap-2">
            {steps.map((s) => {
              const IconComp = s.icon;
              const isActive = step === s.id;
              const isPast = step > s.id;

              return (
                <button
                  key={s.id}
                  type="button"
                  onClick={() => setStep(s.id)}
                  className={`flex items-center space-x-2 p-2 rounded-lg text-left transition-all ${
                    isActive
                      ? 'bg-blue-50 dark:bg-gray-800 border'
                      : 'hover:bg-gray-50 dark:hover:bg-gray-800'
                  }`}
                  style={{
                    borderColor: isActive ? accentColor : 'transparent',
                  }}
                >
                  <div
                    className={`w-7 h-7 rounded-full flex items-center justify-center flex-shrink-0 text-xs font-bold ${
                      isPast
                        ? 'bg-green-600 text-white'
                        : isActive
                        ? 'text-white'
                        : 'bg-gray-200 dark:bg-gray-700 text-gray-600 dark:text-gray-300'
                    }`}
                    style={isActive ? { backgroundColor: accentColor } : {}}
                  >
                    {isPast ? <Check size={14} /> : s.id}
                  </div>
                  <div className="hidden sm:flex flex-col">
                    <span
                      className={`text-xs font-semibold ${
                        isActive ? 'text-gray-900 dark:text-gray-100' : 'text-gray-500'
                      }`}
                    >
                      {s.title}
                    </span>
                  </div>
                </button>
              );
            })}
          </div>
        </div>

        {/* STEP 1: API Key */}
        {step === 1 && (
          <div className="space-y-4">
            <div className="p-4 rounded-lg bg-blue-50 dark:bg-gray-800/60 border border-blue-200 dark:border-gray-700">
              <h4 className="text-sm font-semibold text-blue-900 dark:text-blue-300 flex items-center space-x-2">
                <span>Passo 1: Obtenha a Chave de API no Painel do Asaas</span>
              </h4>
              <p className="text-xs text-blue-800 dark:text-gray-300 mt-1">
                A chave de API é o token que permite ao GestoreMio emitir cobranças, Pix e Boletos no Asaas com total segurança.
              </p>
              <div className="mt-3 flex items-center space-x-2">
                <a
                  href={asaasUrl}
                  target="_blank"
                  rel="noreferrer"
                  className="inline-flex items-center space-x-1.5 px-3 py-1.5 text-xs font-medium rounded text-white bg-blue-600 hover:bg-blue-700 transition-colors shadow-sm"
                >
                  <span>Acessar Painel do Asaas</span>
                  <ExternalLink size={13} />
                </a>
                <span className="text-xs text-gray-500">
                  {testMode ? '(Ambiente Sandbox)' : '(Ambiente Produção)'}
                </span>
              </div>
            </div>

            <ol className="list-decimal list-inside space-y-2 text-xs text-gray-600 dark:text-gray-400 bg-gray-50 dark:bg-gray-900/40 p-3 rounded border" style={{ borderColor: colors.$20 }}>
              <li>Acesse o painel do Asaas e faça login na sua conta.</li>
              <li>No canto superior direito, clique na sua foto/nome e vá em <b>Configurações da Conta</b>.</li>
              <li>Acesse a aba <b>Integrações</b>.</li>
              <li>Na seção <b>Chaves de API</b>, clique em <b>Gerar nova chave</b> (ou copie a chave existente).</li>
              <li>Cole a chave de API no campo abaixo:</li>
            </ol>

            <div className="space-y-2">
              <label className="text-xs font-medium text-gray-700 dark:text-gray-300 flex items-center justify-between">
                <span>Chave da API (apiKey) *</span>
                <button
                  type="button"
                  onClick={() => setShowApiKey(!showApiKey)}
                  className="text-xs text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 inline-flex items-center space-x-1"
                >
                  {showApiKey ? (
                    <>
                      <EyeOff size={13} />
                      <span>Ocultar chave</span>
                    </>
                  ) : (
                    <>
                      <Eye size={13} />
                      <span>Visualizar chave</span>
                    </>
                  )}
                </button>
              </label>
              <InputField
                type={showApiKey ? 'text' : 'password'}
                placeholder="Ex: $aact_prod_..."
                value={apiKey}
                onValueChange={(val) => updateConfigField('apiKey', val)}
              />
            </div>

            <div className="pt-2 flex items-center justify-between p-3 rounded border" style={{ borderColor: colors.$20 }}>
              <div>
                <span className="text-xs font-medium text-gray-800 dark:text-gray-200 block">
                  Modo Sandbox (Ambiente de Testes)
                </span>
                <span className="text-xs text-gray-500 block">
                  Ative se estiver utilizando chaves de teste da plataforma sandbox.asaas.com.
                </span>
              </div>
              <Toggle
                checked={testMode}
                onValueChange={(checked) => updateConfigField('testMode', checked)}
              />
            </div>
          </div>
        )}

        {/* STEP 2: Webhook Dynamic Link */}
        {step === 2 && (
          <div className="space-y-4">
            <div className="p-4 rounded-lg bg-emerald-50 dark:bg-gray-800/60 border border-emerald-200 dark:border-gray-700">
              <h4 className="text-sm font-semibold text-emerald-900 dark:text-emerald-300 flex items-center space-x-2">
                <span>Passo 2: Configure o Webhook no Painel do Asaas</span>
              </h4>
              <p className="text-xs text-emerald-800 dark:text-gray-300 mt-1">
                O Webhook é o canal dinâmico que o Asaas usa para notificar o GestoreMio assim que o cliente paga um Pix ou Boleto, dando baixa imediata e automática na fatura.
              </p>
            </div>

            {/* Dynamic Webhook URL Box */}
            <div className="p-4 rounded-lg border bg-gray-50 dark:bg-gray-900" style={{ borderColor: colors.$20 }}>
              <div className="flex items-center justify-between mb-1.5">
                <span className="text-xs font-bold text-gray-700 dark:text-gray-300 uppercase tracking-wide">
                  URL do Webhook (Gerada Dinamicamente)
                </span>
                {isSaved && (
                  <span className="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-medium bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-300">
                    Ativa & Vinculada à Empresa
                  </span>
                )}
              </div>

              <div className="flex items-center space-x-2">
                <input
                  type="text"
                  readOnly
                  value={webhookUrl}
                  className="flex-1 font-mono text-xs px-3 py-2 rounded border bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 select-all"
                  style={{ borderColor: colors.$24 }}
                />
                <Button
                  behavior="button"
                  type="primary"
                  onClick={() => handleCopy(webhookUrl, 'url')}
                  className="whitespace-nowrap"
                >
                  {isCopiedUrl ? <Check size={14} /> : <Copy size={14} />}
                  <span className="ml-1 text-xs">
                    {isCopiedUrl ? 'Copiado!' : 'Copiar URL'}
                  </span>
                </Button>
              </div>

              {!isSaved && (
                <p className="text-xs text-amber-600 dark:text-amber-400 mt-2 flex items-center space-x-1">
                  <AlertCircle size={13} />
                  <span>
                    O ID permanente será fixado assim que você salvar este gateway.
                  </span>
                </p>
              )}
            </div>

            {/* Webhook Token Box */}
            <div className="p-4 rounded-lg border bg-gray-50 dark:bg-gray-900" style={{ borderColor: colors.$20 }}>
              <div className="flex items-center justify-between mb-1.5">
                <span className="text-xs font-bold text-gray-700 dark:text-gray-300 uppercase tracking-wide">
                  Token de Autenticação do Webhook (Header: asaas-access-token)
                </span>
                <span className="text-xs text-gray-500">
                  Garante a autenticidade dos dados recebidos
                </span>
              </div>

              <div className="flex items-center space-x-2">
                <input
                  type="text"
                  value={webhookToken}
                  placeholder="Defina um segredo ou clique em Gerar"
                  onChange={(e) => updateConfigField('webhookToken', e.target.value)}
                  className="flex-1 font-mono text-xs px-3 py-2 rounded border bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100"
                  style={{ borderColor: colors.$24 }}
                />
                <Button
                  behavior="button"
                  type="minimal"
                  onClick={handleGenerateSecureToken}
                  className="whitespace-nowrap"
                >
                  <RefreshCw size={13} className="mr-1" />
                  <span className="text-xs">Gerar Token</span>
                </Button>
                {webhookToken && (
                  <Button
                    behavior="button"
                    type="secondary"
                    onClick={() => handleCopy(webhookToken, 'token')}
                    className="whitespace-nowrap"
                  >
                    {isCopiedToken ? <Check size={14} /> : <Copy size={14} />}
                    <span className="ml-1 text-xs">
                      {isCopiedToken ? 'Copiado!' : 'Copiar'}
                    </span>
                  </Button>
                )}
              </div>
            </div>

            {/* How to configure on Asaas step by step */}
            <div className="space-y-2">
              <h5 className="text-xs font-bold text-gray-700 dark:text-gray-300 uppercase tracking-wider">
                Como configurar no Asaas:
              </h5>
              <ol className="list-decimal list-inside space-y-1.5 text-xs text-gray-600 dark:text-gray-400 bg-gray-50 dark:bg-gray-900/40 p-3 rounded border" style={{ borderColor: colors.$20 }}>
                <li>No painel do Asaas, vá em <b>Configurações da Conta</b> &gt; <b>Integrações</b>.</li>
                <li>Na seção <b>Webhooks</b>, localize <b>Webhooks para Cobranças</b> e clique em <b>Configurar Webhook</b>.</li>
                <li>No campo <b>URL</b>, cole a URL gerada dinamicamente acima.</li>
                <li>No campo <b>Token de Autenticação</b>, cole o Token acima.</li>
                <li>Em <b>Versão da API</b>, selecione <b>v3</b> e marque a situação como <b>Ativo</b>.</li>
                <li>
                  Selecione os seguintes eventos de cobrança:
                  <div className="mt-2 flex flex-wrap gap-1.5 pl-4">
                    {supportedEvents.map((evt) => (
                      <span
                        key={evt.name}
                        className="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-mono font-medium bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300"
                      >
                        ✓ {evt.name}
                      </span>
                    ))}
                  </div>
                </li>
                <li className="mt-2">Clique no botão <b>Salvar</b> no Asaas. Pronto!</li>
              </ol>
            </div>
          </div>
        )}

        {/* STEP 3: NFS-e */}
        {step === 3 && (
          <div className="space-y-4">
            <div className="p-4 rounded-lg bg-purple-50 dark:bg-gray-800/60 border border-purple-200 dark:border-gray-700">
              <h4 className="text-sm font-semibold text-purple-900 dark:text-purple-300 flex items-center space-x-2">
                <span>Passo 3: Emissão Automática de NFS-e (Opcional)</span>
              </h4>
              <p className="text-xs text-purple-800 dark:text-gray-300 mt-1">
                O GestoreMio pode solicitar ao Asaas a emissão automática da Nota Fiscal de Serviço (NFS-e) assim que o pagamento for confirmado.
              </p>
            </div>

            <div className="space-y-4">
              <div className="flex items-center justify-between p-3 rounded border" style={{ borderColor: colors.$20 }}>
                <div>
                  <span className="text-xs font-medium text-gray-800 dark:text-gray-200 block">
                    Emitir NFS-e Automaticamente
                  </span>
                  <span className="text-xs text-gray-500 block">
                    Ao confirmar o pagamento de uma fatura, agenda a emissão da NFS-e municipal no Asaas.
                  </span>
                </div>
                <Toggle
                  checked={emitNfeAutomatically}
                  onValueChange={(checked) =>
                    updateConfigField('emitNfeAutomatically', checked)
                  }
                />
              </div>

              <div className="space-y-2">
                <label className="text-xs font-medium text-gray-700 dark:text-gray-300 block">
                  Código de Tributação Municipal (municipalServiceCode)
                </label>
                <InputField
                  type="text"
                  placeholder="Ex: 010701 ou código fornecido pela prefeitura no Asaas"
                  value={municipalServiceCode}
                  onValueChange={(val) =>
                    updateConfigField('municipalServiceCode', val)
                  }
                />
                <p className="text-[11px] text-gray-500">
                  Consulte no painel do Asaas em <b>Notas Fiscais</b> &gt; <b>Configurações Municipais</b> para encontrar o código cadastrado para sua atividade.
                </p>
              </div>

              <div className="p-3 rounded bg-gray-50 dark:bg-gray-900 border text-xs text-gray-600 dark:text-gray-400" style={{ borderColor: colors.$20 }}>
                <p className="font-semibold text-gray-800 dark:text-gray-200 mb-1">
                  💡 Webhook de Notas Fiscais no Asaas:
                </p>
                <p>
                  Para que o link do PDF da NFS-e e o número da nota sejam atualizados automaticamente nas faturas, configure também no Asaas o webhook de <b>Notas Fiscais de Serviço</b> com a mesma URL e Token, marcando os eventos <code>INVOICE_AUTHORIZED</code> e <code>INVOICE_ERROR</code>.
                </p>
              </div>
            </div>
          </div>
        )}

        {/* STEP 4: Validation & Health Check */}
        {step === 4 && (
          <div className="space-y-4">
            <div className="p-4 rounded-lg bg-gray-50 dark:bg-gray-800/60 border" style={{ borderColor: colors.$20 }}>
              <h4 className="text-sm font-semibold text-gray-900 dark:text-gray-100 flex items-center space-x-2">
                <span>Passo 4: Verificação & Teste de Conexão</span>
              </h4>
              <p className="text-xs text-gray-600 dark:text-gray-400 mt-1">
                Revise suas configurações e execute um teste direto com a API do Asaas para confirmar que tudo está operando perfeitamente.
              </p>
            </div>

            {/* Summary Checklist */}
            <div className="space-y-2 border rounded-lg p-3 bg-white dark:bg-gray-900" style={{ borderColor: colors.$20 }}>
              <div className="flex items-center justify-between text-xs py-1 border-b" style={{ borderColor: colors.$20 }}>
                <span className="text-gray-600 dark:text-gray-400">Chave de API:</span>
                <span className="font-medium">
                  {apiKey ? (
                    <span className="text-green-600 flex items-center space-x-1">
                      <Check size={12} />
                      <span>Configurada ({apiKey.slice(0, 14)}...)</span>
                    </span>
                  ) : (
                    <span className="text-red-500 flex items-center space-x-1">
                      <AlertCircle size={12} />
                      <span>Não informada</span>
                    </span>
                  )}
                </span>
              </div>

              <div className="flex items-center justify-between text-xs py-1 border-b" style={{ borderColor: colors.$20 }}>
                <span className="text-gray-600 dark:text-gray-400">Ambiente:</span>
                <span className="font-semibold">
                  {testMode ? 'Sandbox (Testes)' : 'Produção'}
                </span>
              </div>

              <div className="flex items-center justify-between text-xs py-1 border-b" style={{ borderColor: colors.$20 }}>
                <span className="text-gray-600 dark:text-gray-400">Token do Webhook:</span>
                <span className="font-medium">
                  {webhookToken ? (
                    <span className="text-green-600 flex items-center space-x-1">
                      <Check size={12} />
                      <span>Configurado ({webhookToken.slice(0, 10)}...)</span>
                    </span>
                  ) : (
                    <span className="text-amber-500 flex items-center space-x-1">
                      <AlertCircle size={12} />
                      <span>Não informado (Recomendado)</span>
                    </span>
                  )}
                </span>
              </div>

              <div className="flex items-center justify-between text-xs py-1">
                <span className="text-gray-600 dark:text-gray-400">Emissão de NFS-e Automática:</span>
                <span className="font-medium">
                  {emitNfeAutomatically ? 'Ativada' : 'Desativada'}
                </span>
              </div>
            </div>

            {/* Test Connection Button and Feedback */}
            <div className="p-4 rounded-lg border bg-gray-50 dark:bg-gray-900 space-y-3" style={{ borderColor: colors.$20 }}>
              <div className="flex items-center justify-between">
                <div>
                  <h5 className="text-xs font-bold text-gray-800 dark:text-gray-200">
                    Teste de Conectividade em Tempo Real
                  </h5>
                  <p className="text-xs text-gray-500">
                    Consulta a API do Asaas para testar saldo e permissões da chave configurada.
                  </p>
                </div>
                <Button
                  behavior="button"
                  type="primary"
                  onClick={handleTestConnection}
                  disabled={isTesting || !isSaved}
                >
                  <RefreshCw
                    size={13}
                    className={`mr-1.5 ${isTesting ? 'animate-spin' : ''}`}
                  />
                  <span>{isTesting ? 'Testando...' : 'Testar Conexão'}</span>
                </Button>
              </div>

              {!isSaved && (
                <p className="text-xs text-amber-600 dark:text-amber-400">
                  ⚠️ Salve o gateway na tela de configurações antes de executar o teste de conexão.
                </p>
              )}

              {testResult.status === 'success' && (
                <div className="p-3 rounded bg-green-50 dark:bg-green-950/40 border border-green-200 dark:border-green-800 text-xs text-green-800 dark:text-green-200 flex items-start space-x-2">
                  <CheckCircle size={16} className="text-green-600 mt-0.5 flex-shrink-0" />
                  <span>{testResult.message}</span>
                </div>
              )}

              {testResult.status === 'error' && (
                <div className="p-3 rounded bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 text-xs text-red-800 dark:text-red-200 flex items-start space-x-2">
                  <AlertCircle size={16} className="text-red-600 mt-0.5 flex-shrink-0" />
                  <span>{testResult.message}</span>
                </div>
              )}
            </div>
          </div>
        )}

        {/* Modal Navigation Buttons */}
        <div className="flex items-center justify-between pt-4 border-t" style={{ borderColor: colors.$20 }}>
          <div>
            {step > 1 && (
              <Button
                behavior="button"
                type="minimal"
                onClick={() => setStep(step - 1)}
              >
                <ArrowLeft size={14} className="mr-1" />
                <span>Anterior</span>
              </Button>
            )}
          </div>

          <div className="flex items-center space-x-2">
            {step < 4 ? (
              <Button
                behavior="button"
                type="primary"
                onClick={() => setStep(step + 1)}
              >
                <span>Próximo</span>
                <ArrowRight size={14} className="ml-1" />
              </Button>
            ) : (
              <Button
                behavior="button"
                type="primary"
                onClick={() => {
                  onClose();
                  toast.success(
                    'Configuração orientada concluída! Lembre-se de clicar em "Salvar" no topo da página para gravar as alterações.'
                  );
                }}
              >
                <Check size={14} className="mr-1" />
                <span>Concluir Assistente</span>
              </Button>
            )}
          </div>
        </div>
      </div>
    </Modal>
  );
}
