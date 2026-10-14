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
import { FaWhatsapp } from 'react-icons/fa';
import { endpoint } from '$app/common/helpers';
import { request } from '$app/common/helpers/request';
import { toast } from '$app/common/helpers/toast/toast';
import { Invoice } from '$app/common/interfaces/invoice';
import { EntityActionElement } from '$app/components/EntityActionElement';
import { Button, InputField, Textarea } from '$app/components/forms';
import Toggle from '$app/components/forms/Toggle';
import { Modal } from '$app/components/Modal';

interface SendOptions {
  is_configured: boolean;
  configured_message?: string | null;
  phone?: string | null;
  message?: string | null;
  pdf?: { filename: string; url: string } | null;
  boleto?: { filename: string; url: string } | null;
  defaults?: { send_pdf?: boolean; send_boleto?: boolean };
}

interface Props {
  invoice: Invoice;
  isDropdown?: boolean;
}

export function WhatsAppInvoiceAction(props: Props) {
  const { invoice, isDropdown = false } = props;

  const [isModalOpen, setIsModalOpen] = useState<boolean>(false);
  const [options, setOptions] = useState<SendOptions | null>(null);
  const [isLoading, setIsLoading] = useState<boolean>(false);
  const [isSending, setIsSending] = useState<boolean>(false);

  const [phone, setPhone] = useState<string>('');
  const [message, setMessage] = useState<string>('');
  const [sendPdf, setSendPdf] = useState<boolean>(false);
  const [sendBoleto, setSendBoleto] = useState<boolean>(false);

  useEffect(() => {
    if (!isModalOpen) {
      return;
    }

    setIsLoading(true);

    request('GET', endpoint(`/api/v1/invoices/${invoice.id}/whatsapp/options`))
      .then((response) => {
        const data = response.data as SendOptions;

        setOptions(data);
        setPhone(data.phone ?? '');
        setMessage(data.message ?? '');
        setSendPdf(Boolean(data.defaults?.send_pdf));
        setSendBoleto(Boolean(data.defaults?.send_boleto));
      })
      .catch(() => toast.error())
      .finally(() => setIsLoading(false));
  }, [isModalOpen]);

  const send = () => {
    if (isSending) {
      return;
    }

    setIsSending(true);
    toast.processing();

    request('POST', endpoint(`/api/v1/invoices/${invoice.id}/whatsapp`), {
      phone,
      message,
      send_pdf: sendPdf,
      send_boleto: sendBoleto,
    })
      .then((response) => {
        toast.success(response.data?.message ?? 'success');
        setIsModalOpen(false);
      })
      .catch((error: AxiosError<{ error?: string }>) => {
        toast.error(error.response?.data?.error);
      })
      .finally(() => setIsSending(false));
  };

  const notConfigured = options && !options.is_configured;

  return (
    <>
      <EntityActionElement
        {...(!isDropdown && {
          key: 'whatsapp_invoice',
        })}
        entity="invoice"
        actionKey="whatsapp_invoice"
        isCommonActionSection={!isDropdown}
        tooltipText="Enviar WhatsApp"
        onClick={() => setIsModalOpen(true)}
        icon={FaWhatsapp}
        disablePreventNavigation
      >
        WhatsApp
      </EntityActionElement>

      <Modal
        title={`WhatsApp - ${invoice.number}`}
        visible={isModalOpen}
        onClose={() => setIsModalOpen(false)}
        size="regular"
      >
        {isLoading ? (
          <div className="flex justify-center py-8">...</div>
        ) : notConfigured ? (
          <div className="flex flex-col items-center space-y-4 py-4">
            <span className="text-base font-medium text-center">
              {options?.configured_message}
            </span>

            <Button
              className="self-end"
              onClick={() => {
                window.location.href = '/settings/whatsapp';
              }}
            >
              Configurar WhatsApp
            </Button>
          </div>
        ) : (
          <div className="flex flex-col space-y-4">
            <InputField
              id="whatsapp_phone"
              label="Telefone (WhatsApp)"
              value={phone}
              onValueChange={(value) => setPhone(value)}
              changeOverride
            />

            <Textarea
              id="whatsapp_message"
              label="Mensagem"
              rows={7}
              value={message}
              onChange={(event: any) => setMessage(event.target.value)}
            />

            <div className="flex flex-col space-y-3">
              <div className="flex items-center space-x-2">
                <Toggle checked={sendPdf} onValueChange={setSendPdf} />
                <span>Anexar PDF da fatura</span>
                {options?.pdf && (
                  <button
                    type="button"
                    className="text-sm text-blue-600 hover:underline"
                    onClick={() =>
                      window.open(endpoint(options.pdf!.url), '_blank')
                    }
                  >
                    (visualizar)
                  </button>
                )}
              </div>

              {options?.boleto && (
                <div className="flex items-center space-x-2">
                  <Toggle checked={sendBoleto} onValueChange={setSendBoleto} />
                  <span>Anexar Boleto</span>
                  <button
                    type="button"
                    className="text-sm text-blue-600 hover:underline"
                    onClick={() =>
                      window.open(endpoint(options.boleto!.url), '_blank')
                    }
                  >
                    (visualizar)
                  </button>
                </div>
              )}
            </div>

            <div className="flex justify-end space-x-2 pt-2">
              <Button
                type="secondary"
                onClick={() => setIsModalOpen(false)}
              >
                Cancelar
              </Button>
              <Button onClick={send} disabled={isSending || !phone}>
                Enviar WhatsApp
              </Button>
            </div>
          </div>
        )}
      </Modal>
    </>
  );
}
