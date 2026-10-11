/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2026. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

import { AxiosError } from 'axios';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useColorScheme } from '$app/common/colors';
import { endpoint } from '$app/common/helpers';
import { request } from '$app/common/helpers/request';
import { toast } from '$app/common/helpers/toast/toast';
import { useTitle } from '$app/common/hooks/useTitle';
import { Card, Element } from '$app/components/cards';
import { Button, InputField, SelectField, Textarea } from '$app/components/forms';
import Toggle from '$app/components/forms/Toggle';
import { Settings } from '$app/components/layouts/Settings';
import { Modal } from '$app/components/Modal';

interface WhatsAppConfig {
  provider: string;
  enabled: boolean;
  send_pdf: boolean;
  send_boleto: boolean;
  auto_send_on_create: boolean;
  message_template: string;
  evolution_api_url: string;
  evolution_instance: string;
  meta_phone_number_id: string;
}

const defaultConfig: WhatsAppConfig = {
  provider: 'evolution',
  enabled: false,
  send_pdf: false,
  send_boleto: false,
  auto_send_on_create: false,
  message_template: '',
  evolution_api_url: '',
  evolution_instance: '',
  meta_phone_number_id: '',
};

export function WhatsApp() {
  const [t] = useTranslation();
  const colors = useColorScheme();
  useTitle('WhatsApp', false);

  const pages = [
    { name: t('settings'), href: '/settings' },
    { name: 'WhatsApp', href: '/settings/whatsapp' },
  ];

  const [config, setConfig] = useState<WhatsAppConfig>(defaultConfig);

  const [evolutionApiKey, setEvolutionApiKey] = useState<string>('');
  const [metaAccessToken, setMetaAccessToken] = useState<string>('');

  const [evolutionApiKeyMask, setEvolutionApiKeyMask] = useState<string>('');
  const [metaAccessTokenMask, setMetaAccessTokenMask] = useState<string>('');

  const [isSaving, setIsSaving] = useState<boolean>(false);

  const [status, setStatus] = useState<string>('unconfigured');
  const [isConnected, setIsConnected] = useState<boolean>(false);

  const [isQrModalOpen, setIsQrModalOpen] = useState<boolean>(false);
  const [isLoadingQr, setIsLoadingQr] = useState<boolean>(false);
  const [qrImage, setQrImage] = useState<string | null>(null);
  const [pairingCode, setPairingCode] = useState<string | null>(null);

  const [testPhone, setTestPhone] = useState<string>('');
  const [testMessage, setTestMessage] = useState<string>('');
  const [isSendingTest, setIsSendingTest] = useState<boolean>(false);

  const updateConfig = (key: keyof WhatsAppConfig, value: unknown) =>
    setConfig((current) => ({ ...current, [key]: value }));

  const loadConfig = () => {
    request('GET', endpoint('/api/v1/whatsapp/config'))
      .then((response) => {
        const data = response.data?.config ?? {};

        setConfig({
          provider: data.provider ?? 'evolution',
          enabled: Boolean(data.enabled),
          send_pdf: Boolean(data.send_pdf),
          send_boleto: Boolean(data.send_boleto),
          auto_send_on_create: Boolean(data.auto_send_on_create),
          message_template: data.message_template ?? '',
          evolution_api_url: data.evolution_api_url ?? '',
          evolution_instance: data.evolution_instance ?? '',
          meta_phone_number_id: data.meta_phone_number_id ?? '',
        });

        setEvolutionApiKeyMask(data.evolution_api_key_masked ?? '');
        setMetaAccessTokenMask(data.meta_access_token_masked ?? '');
      })
      .catch(() => toast.error());
  };

  const loadStatus = () => {
    request('GET', endpoint('/api/v1/whatsapp/status'))
      .then((response) => {
        setStatus(response.data?.state ?? 'unconfigured');
        setIsConnected(Boolean(response.data?.connected));
      })
      .catch(() => undefined);
  };

  useEffect(() => {
    loadConfig();
    loadStatus();
  }, []);

  useEffect(() => {
    if (!isQrModalOpen || isConnected) {
      return;
    }

    const interval = setInterval(() => {
      request('GET', endpoint('/api/v1/whatsapp/status'))
        .then((response) => {
          const connected = Boolean(response.data?.connected);

          setStatus(response.data?.state ?? '');
          setIsConnected(connected);

          if (connected) {
            setIsQrModalOpen(false);
            toast.success('success');
          }
        })
        .catch(() => undefined);
    }, 5000);

    return () => clearInterval(interval);
  }, [isQrModalOpen, isConnected]);

  const onSave = () => {
    if (isSaving) {
      return;
    }

    setIsSaving(true);
    toast.processing();

    const payload: Record<string, unknown> = {
      provider: config.provider,
      enabled: config.enabled,
      send_pdf: config.send_pdf,
      send_boleto: config.send_boleto,
      auto_send_on_create: config.auto_send_on_create,
      message_template: config.message_template || null,
    };

    if (config.provider === 'evolution') {
      payload.evolution_api_url = config.evolution_api_url || '';
      payload.evolution_instance = config.evolution_instance || '';

      if (evolutionApiKey) {
        payload.evolution_api_key = evolutionApiKey;
      }
    } else {
      payload.meta_phone_number_id = config.meta_phone_number_id || '';

      if (metaAccessToken) {
        payload.meta_access_token = metaAccessToken;
      }
    }

    request('POST', endpoint('/api/v1/whatsapp/config'), payload)
      .then((response) => {
        setEvolutionApiKey('');
        setMetaAccessToken('');
        loadConfig();
        loadStatus();

        if (response.data?.test_connection?.success) {
          toast.success('success');
        } else {
          toast.success('success');
          toast.info(
            response.data?.test_connection?.message ??
              'Configurações salvas. Verifique as credenciais para conectar.'
          );
        }
      })
      .catch((error: AxiosError<{ message?: string }>) => {
        toast.error(error.response?.data?.message);
      })
      .finally(() => setIsSaving(false));
  };

  const testConnection = () => {
    toast.processing();

    request('POST', endpoint('/api/v1/whatsapp/test'))
      .then((response) => {
        toast.success(response.data?.message ?? 'success');
        loadStatus();
      })
      .catch((error: AxiosError<{ message?: string }>) => {
        toast.error(error.response?.data?.message);
      });
  };

  const connect = () => {
    setIsQrModalOpen(true);
    setIsLoadingQr(true);
    setQrImage(null);
    setPairingCode(null);

    request('GET', endpoint('/api/v1/whatsapp/qrcode'))
      .then((response) => {
        const data = response.data;

        if (data?.connected) {
          setIsConnected(true);
          setStatus('open');
          setIsQrModalOpen(false);
          return;
        }

        let image = data?.qrcode ?? null;

        if (image && !String(image).startsWith('data:')) {
          image = `data:image/png;base64,${image}`;
        }

        setQrImage(image);
        setPairingCode(data?.pairing_code ?? data?.code ?? null);
      })
      .catch(() => toast.error())
      .finally(() => setIsLoadingQr(false));
  };

  const disconnect = () => {
    toast.processing();

    request('POST', endpoint('/api/v1/whatsapp/disconnect'))
      .then(() => {
        toast.success('success');
        loadStatus();
      })
      .catch(() => toast.error());
  };

  const sendTest = () => {
    if (isSendingTest) {
      return;
    }

    setIsSendingTest(true);
    toast.processing();

    request('POST', endpoint('/api/v1/whatsapp/send_test'), {
      phone: testPhone,
      message: testMessage,
    })
      .then(() => {
        toast.success('success');
        setTestMessage('');
      })
      .catch((error: AxiosError<{ error?: string }>) => {
        toast.error(error.response?.data?.error);
      })
      .finally(() => setIsSendingTest(false));
  };

  const statusColor = isConnected
    ? 'bg-green-500'
    : status === 'connecting'
      ? 'bg-yellow-500'
      : 'bg-red-500';

  return (
    <Settings
      title="WhatsApp"
      breadcrumbs={pages}
      onSaveClick={onSave}
      disableSaveButton={isSaving}
    >
      <Card
        title="Geral"
        className="shadow-sm"
        style={{ borderColor: colors.$24 }}
        headerStyle={{ borderColor: colors.$20 }}
      >
        <Element leftSide="Provedor">
          <SelectField
            value={config.provider}
            onValueChange={(value) => updateConfig('provider', value)}
          >
            <option value="evolution">Evolution API</option>
            <option value="meta">Meta Cloud API</option>
          </SelectField>
        </Element>

        <Element leftSide="Habilitar WhatsApp">
          <Toggle
            checked={config.enabled}
            onValueChange={(value) => updateConfig('enabled', value)}
          />
        </Element>

        <Element leftSide="Anexar PDF da fatura por padrão">
          <Toggle
            checked={config.send_pdf}
            onValueChange={(value) => updateConfig('send_pdf', value)}
          />
        </Element>

        <Element leftSide="Anexar Boleto (Asaas) por padrão">
          <Toggle
            checked={config.send_boleto}
            onValueChange={(value) => updateConfig('send_boleto', value)}
          />
        </Element>

        <Element leftSide="Enviar automaticamente ao criar fatura">
          <Toggle
            checked={config.auto_send_on_create}
            onValueChange={(value) => updateConfig('auto_send_on_create', value)}
          />
        </Element>

        <Element leftSide="Modelo da mensagem">
          <Textarea
            id="message_template"
            rows={8}
            value={config.message_template}
            placeholder="Olá, *:contact*! Sua fatura *:invoice*..."
            onChange={(event) =>
              updateConfig('message_template', event.target.value)
            }
          />

          <div className="mt-2 text-xs text-gray-500">
            Variáveis:{' '}
            {':contact, :client, :invoice, :amount, :due_date, :link, :company'}
          </div>
        </Element>
      </Card>

      {config.provider === 'evolution' && (
        <Card
          title="Evolution API"
          className="shadow-sm"
          style={{ borderColor: colors.$24 }}
          headerStyle={{ borderColor: colors.$20 }}
        >
          <Element leftSide="URL da API">
            <InputField
              id="evolution_api_url"
              value={config.evolution_api_url}
              placeholder="https://evolution.suaempresa.com.br"
              onValueChange={(value) => updateConfig('evolution_api_url', value)}
              changeOverride
            />
          </Element>

          <Element leftSide="API Key">
            <InputField
              id="evolution_api_key"
              type="password"
              value={evolutionApiKey}
              placeholder={
                evolutionApiKeyMask ? `Atual: ${evolutionApiKeyMask}` : ''
              }
              onValueChange={setEvolutionApiKey}
              changeOverride
            />
          </Element>

          <Element leftSide="Instância">
            <InputField
              id="evolution_instance"
              value={config.evolution_instance}
              placeholder="gestoremio"
              onValueChange={(value) => updateConfig('evolution_instance', value)}
              changeOverride
            />
          </Element>
        </Card>
      )}

      {config.provider === 'meta' && (
        <Card
          title="Meta Cloud API"
          className="shadow-sm"
          style={{ borderColor: colors.$24 }}
          headerStyle={{ borderColor: colors.$20 }}
        >
          <Element leftSide="Phone Number ID">
            <InputField
              id="meta_phone_number_id"
              value={config.meta_phone_number_id}
              onValueChange={(value) =>
                updateConfig('meta_phone_number_id', value)
              }
              changeOverride
            />
          </Element>

          <Element leftSide="Access Token">
            <InputField
              id="meta_access_token"
              type="password"
              value={metaAccessToken}
              placeholder={
                metaAccessTokenMask ? `Atual: ${metaAccessTokenMask}` : ''
              }
              onValueChange={setMetaAccessToken}
              changeOverride
            />
          </Element>
        </Card>
      )}

      <Card
        title="Conexão"
        className="shadow-sm"
        style={{ borderColor: colors.$24 }}
        headerStyle={{ borderColor: colors.$20 }}
      >
        <Element leftSide="Status">
          <div className="flex items-center space-x-2">
            <span className={`inline-block h-3 w-3 rounded-full ${statusColor}`} />
            <span className="text-sm capitalize">
              {isConnected ? 'Conectado' : status || 'Desconectado'}
            </span>
          </div>
        </Element>

        <Element leftSide="Ações">
          <div className="flex flex-wrap gap-2">
            <Button type="secondary" onClick={testConnection}>
              {t('test') || 'Testar conexão'}
            </Button>

            {config.provider === 'evolution' && (
              <Button type="secondary" onClick={connect}>
                Conectar (QR Code)
              </Button>
            )}

            {isConnected && (
              <Button type="secondary" onClick={disconnect}>
                Desconectar
              </Button>
            )}
          </div>
        </Element>
      </Card>

      <Card
        title="Mensagem de teste"
        className="shadow-sm"
        style={{ borderColor: colors.$24 }}
        headerStyle={{ borderColor: colors.$20 }}
      >
        <Element leftSide="Telefone">
          <InputField
            id="test_phone"
            value={testPhone}
            placeholder="(11) 99999-9999"
            onValueChange={setTestPhone}
            changeOverride
          />
        </Element>

        <Element leftSide="Mensagem">
          <Textarea
            id="test_message"
            rows={3}
            value={testMessage}
            placeholder="Olá! Esta é uma mensagem de teste."
            onChange={(event) => setTestMessage(event.target.value)}
          />
        </Element>

        <Element leftSide=" ">
          <Button
            type="secondary"
            onClick={sendTest}
            disabled={isSendingTest || !testPhone}
          >
            Enviar teste
          </Button>
        </Element>
      </Card>

      <Modal
        title="Conectar WhatsApp"
        visible={isQrModalOpen}
        onClose={() => setIsQrModalOpen(false)}
        size="small"
      >
        <div className="flex flex-col items-center space-y-4 py-2">
          {isLoadingQr ? (
            <span className="text-sm">Gerando QR Code...</span>
          ) : qrImage ? (
            <>
              <img src={qrImage} alt="WhatsApp QR Code" className="h-64 w-64" />
              <span className="text-sm text-center text-gray-500">
                Abra o WhatsApp, vá em Aparelhos conectados e escaneie o código.
              </span>
            </>
          ) : pairingCode ? (
            <>
              <span className="text-sm font-medium">
                Código de pareamento:
              </span>
              <span className="text-2xl font-bold tracking-widest">
                {pairingCode}
              </span>
            </>
          ) : (
            <span className="text-sm text-center text-gray-500">
              Não foi possível gerar o QR Code. Verifique as credenciais da
              Evolution API e tente novamente.
            </span>
          )}
        </div>
      </Modal>
    </Settings>
  );
}
