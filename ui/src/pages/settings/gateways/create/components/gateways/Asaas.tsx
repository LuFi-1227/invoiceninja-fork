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
import { ValidationBag } from '$app/common/interfaces/validation-bag';
import { Element } from '$app/components/cards';
import { Button, InputField } from '$app/components/forms';
import Toggle from '$app/components/forms/Toggle';
import { AsaasWizardModal } from './AsaasWizardModal';

interface Props {
  gateway: Gateway;
  companyGateway: CompanyGateway;
  setCompanyGateway: React.Dispatch<
    React.SetStateAction<CompanyGateway | undefined>
  >;
  errors: ValidationBag | undefined;
  isGatewaySaved?: boolean;
}

export function Asaas(props: Props) {
  const [t] = useTranslation();
  const colors = useColorScheme();
  const accentColor = useAccentColor();
  const company = useCurrentCompany();

  const [isWizardOpen, setIsWizardOpen] = useState<boolean>(false);
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
  const parsedConfig = props.companyGateway?.config
    ? JSON.parse(props.companyGateway.config)
    : {};
  const apiKey = parsedConfig.apiKey || '';
  const webhookToken = parsedConfig.webhookToken || '';
  const testMode = Boolean(parsedConfig.testMode);
  const emitNfeAutomatically = parsedConfig.emitNfeAutomatically ?? true;
  const municipalServiceCode = parsedConfig.municipalServiceCode || '';

  // Dynamic Webhook URL
  const companyKey = company?.company_key || '';
  const companyGatewayId = props.companyGateway?.id || '';
  const isSaved = Boolean(companyGatewayId && companyGatewayId.length > 0);
  const webhookUrl = isSaved
    ? `${apiEndpoint()}/payment_webhook/${companyKey}/${companyGatewayId}`
    : `${apiEndpoint()}/payment_webhook/${companyKey}/[SALVE_O_GATEWAY_PRIMEIRO]`;

  const updateConfigField = (field: string, value: string | boolean) => {
    props.setCompanyGateway((prev) => {
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
              'Conexão com a API do Asaas validada com sucesso! As credenciais estão operacionais.',
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
    { name: 'INVOICE_ERROR', label: 'Erro NFS-e' },
  ];

  return (
    <>
      {/* Wizard Banner */}
      <div className="p-4 sm:p-5 m-4 sm:m-6 rounded-xl border bg-gradient-to-r from-blue-50/80 via-indigo-50/50 to-white dark:from-gray-800/80 dark:via-gray-800/40 dark:to-gray-900 shadow-sm" style={{ borderColor: colors.$20 }}>
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div className="flex items-start space-x-3.5">
            <div
              className="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm"
              style={{ backgroundColor: `${accentColor}18` }}
            >
              <Zap size={22} color={accentColor} />
            </div>
            <div>
              <h4 className="font-bold text-sm text-gray-900 dark:text-gray-100 flex items-center space-x-2">
                <span>Assistente de Configuração do Asaas</span>
                <span className="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300">
                  Wizard Guiado
                </span>
              </h4>
              <p className="text-xs text-gray-600 dark:text-gray-400 mt-0.5 max-w-xl">
                Configure as chaves de API, gere o link de webhook dinâmico para baixa automática de Pix/Boleto e ative a emissão de notas fiscais municipais (NFS-e) em 4 passos simples.
              </p>
            </div>
          </div>

          <div className="flex items-center space-x-2 flex-shrink-0">
            <Button
              behavior="button"
              type="primary"
              onClick={() => setIsWizardOpen(true)}
              className="shadow-sm"
            >
              <Zap size={14} className="mr-1.5" />
              <span>Abrir Assistente (Wizard)</span>
            </Button>
          </div>
        </div>
      </div>

      {/* Dynamic Webhook Card */}
      <div className="mx-4 sm:mx-6 mb-4 p-4 sm:p-5 rounded-xl border bg-white dark:bg-gray-900 shadow-sm space-y-4" style={{ borderColor: colors.$20 }}>
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 border-b pb-3" style={{ borderColor: colors.$20 }}>
          <div className="flex items-center space-x-2">
            <Shield size={18} color={accentColor} />
            <div>
              <h4 className="text-xs font-bold uppercase tracking-wider text-gray-800 dark:text-gray-200">
                Webhook Dinâmico de Pagamentos (Sincronização em Tempo Real)
              </h4>
              <p className="text-[11px] text-gray-500">
                O Asaas utiliza este endereço exclusivo para atualizar o status das faturas assim que forem pagas.
              </p>
            </div>
          </div>

          {isSaved ? (
            <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-300">
              <Check size={12} className="mr-1" />
              Link Pronto para Uso
            </span>
          ) : (
            <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-300">
              Salve o gateway para ativar o ID final
            </span>
          )}
        </div>

        {/* Dynamic Webhook URL input + copy */}
        <div className="space-y-1.5">
          <label className="text-xs font-medium text-gray-700 dark:text-gray-300 flex items-center justify-between">
            <span>URL do Webhook para o Painel do Asaas:</span>
            <span className="text-[11px] text-gray-500">
              Cole no campo "URL" em Configurações &gt; Integrações &gt; Webhooks no Asaas
            </span>
          </label>
          <div className="flex items-center space-x-2">
            <input
              type="text"
              readOnly
              value={webhookUrl}
              className="flex-1 font-mono text-xs px-3 py-2 rounded-lg border bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-gray-100 select-all"
              style={{ borderColor: colors.$24 }}
            />
            <Button
              behavior="button"
              type="primary"
              onClick={() => handleCopy(webhookUrl, 'url')}
              className="whitespace-nowrap shadow-sm"
            >
              {isCopiedUrl ? <Check size={14} className="mr-1" /> : <Copy size={14} className="mr-1" />}
              <span>{isCopiedUrl ? 'Copiado!' : 'Copiar URL'}</span>
            </Button>
          </div>
        </div>

        {/* Webhook Token input + generate + copy */}
        <div className="space-y-1.5 pt-1">
          <label className="text-xs font-medium text-gray-700 dark:text-gray-300 flex items-center justify-between">
            <span>Token de Autenticação do Webhook (webhookToken):</span>
            <span className="text-[11px] text-gray-500">
              Enviado pelo Asaas no cabeçalho <code>asaas-access-token</code>
            </span>
          </label>
          <div className="flex items-center space-x-2">
            <input
              type="text"
              value={webhookToken}
              placeholder="Digite um segredo ou clique em Gerar"
              onChange={(e) => updateConfigField('webhookToken', e.target.value)}
              className="flex-1 font-mono text-xs px-3 py-2 rounded-lg border bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100"
              style={{ borderColor: colors.$24 }}
            />
            <Button
              behavior="button"
              type="minimal"
              onClick={handleGenerateSecureToken}
              className="whitespace-nowrap"
            >
              <RefreshCw size={13} className="mr-1" />
              <span>Gerar Token</span>
            </Button>
            {webhookToken && (
              <Button
                behavior="button"
                type="secondary"
                onClick={() => handleCopy(webhookToken, 'token')}
                className="whitespace-nowrap"
              >
                {isCopiedToken ? <Check size={14} className="mr-1" /> : <Copy size={14} className="mr-1" />}
                <span>{isCopiedToken ? 'Copiado!' : 'Copiar'}</span>
              </Button>
            )}
          </div>
        </div>

        {/* Supported Events Tags */}
        <div className="pt-2">
          <span className="text-[11px] font-semibold text-gray-600 dark:text-gray-400 block mb-1.5">
            Eventos que devem ser marcados no Asaas (Versão da API: v3):
          </span>
          <div className="flex flex-wrap gap-1.5">
            {supportedEvents.map((evt) => (
              <span
                key={evt.name}
                className="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-mono font-medium bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300 border border-blue-200 dark:border-blue-800/60"
                title={evt.label}
              >
                <Check size={10} className="mr-1 text-green-600 dark:text-green-400" />
                {evt.name}
              </span>
            ))}
          </div>
        </div>
      </div>

      {/* Credentials Fields */}
      <Element
        leftSide="Chave da API (apiKey)"
        leftSideHelp="Chave de integração gerada no painel do Asaas em Configurações > Integrações > Chaves de API."
      >
        <div className="space-y-1.5 w-full">
          <div className="flex items-center space-x-2">
            <InputField
              type={showApiKey ? 'text' : 'password'}
              placeholder="$aact_prod_..."
              value={apiKey}
              onValueChange={(val) => updateConfigField('apiKey', val)}
              errorMessage={props.errors?.errors?.apiKey}
            />
            <button
              type="button"
              onClick={() => setShowApiKey(!showApiKey)}
              className="p-2 border rounded-md text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 transition-colors"
              style={{ borderColor: colors.$24 }}
              title={showApiKey ? 'Ocultar chave' : 'Visualizar chave'}
            >
              {showApiKey ? <EyeOff size={16} /> : <Eye size={16} />}
            </button>
            <a
              href={asaasUrl}
              target="_blank"
              rel="noreferrer"
              className="p-2 border rounded-md text-blue-600 hover:bg-blue-50 dark:hover:bg-gray-800 transition-colors inline-flex items-center justify-center"
              style={{ borderColor: colors.$24 }}
              title="Acessar painel do Asaas"
            >
              <ExternalLink size={16} />
            </a>
          </div>
        </div>
      </Element>

      <Element
        leftSide="Modo Sandbox (Testes)"
        leftSideHelp="Habilite se estiver utilizando uma conta de testes na plataforma sandbox.asaas.com."
      >
        <Toggle
          checked={testMode}
          onValueChange={(checked) => updateConfigField('testMode', checked)}
        />
      </Element>

      <Element
        leftSide="Emitir NFS-e Automaticamente"
        leftSideHelp="Quando ativado, agenda a emissão da Nota Fiscal de Serviço no Asaas automaticamente ao confirmar o pagamento da fatura."
      >
        <Toggle
          checked={emitNfeAutomatically}
          onValueChange={(checked) =>
            updateConfigField('emitNfeAutomatically', checked)
          }
        />
      </Element>

      <Element
        leftSide="Código de Tributação Municipal"
        leftSideHelp="Código de serviço do município cadastrado no Asaas para emissão de Notas Fiscais (NFS-e)."
      >
        <InputField
          type="text"
          placeholder="Ex: 010701"
          value={municipalServiceCode}
          onValueChange={(val) =>
            updateConfigField('municipalServiceCode', val)
          }
          errorMessage={props.errors?.errors?.municipalServiceCode}
        />
      </Element>

      {/* Health Check Element */}
      {isSaved && (
        <Element
          leftSide="Verificação de Conexão"
          leftSideHelp="Testa a comunicação direta com a API do Asaas utilizando a chave de API cadastrada."
        >
          <div className="space-y-2">
            <div className="flex items-center space-x-3">
              <Button
                behavior="button"
                type="secondary"
                onClick={handleTestConnection}
                disabled={isTesting || !apiKey}
              >
                <RefreshCw
                  size={14}
                  className={`mr-1.5 ${isTesting ? 'animate-spin' : ''}`}
                />
                <span>{isTesting ? 'Verificando...' : 'Testar Conexão com Asaas'}</span>
              </Button>
            </div>

            {testResult.status === 'success' && (
              <div className="p-3 rounded-lg bg-green-50 dark:bg-green-950/40 border border-green-200 dark:border-green-800 text-xs text-green-800 dark:text-green-200 flex items-start space-x-2">
                <CheckCircle size={16} className="text-green-600 mt-0.5 flex-shrink-0" />
                <span>{testResult.message}</span>
              </div>
            )}

            {testResult.status === 'error' && (
              <div className="p-3 rounded-lg bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 text-xs text-red-800 dark:text-red-200 flex items-start space-x-2">
                <AlertCircle size={16} className="text-red-600 mt-0.5 flex-shrink-0" />
                <span>{testResult.message}</span>
              </div>
            )}
          </div>
        </Element>
      )}

      {/* Asaas Wizard Modal */}
      <AsaasWizardModal
        visible={isWizardOpen}
        onClose={() => setIsWizardOpen(false)}
        gateway={props.gateway}
        companyGateway={props.companyGateway}
        setCompanyGateway={props.setCompanyGateway}
        isGatewaySaved={props.isGatewaySaved}
      />
    </>
  );
}
