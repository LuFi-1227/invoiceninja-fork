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
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { MdTextSnippet } from 'react-icons/md';
import { EntityState } from '$app/common/enums/entity-state';
import { endpoint, getEntityState } from '$app/common/helpers';
import { request } from '$app/common/helpers/request';
import { toast } from '$app/common/helpers/toast/toast';
import { $refetch } from '$app/common/hooks/useRefetch';
import { Invoice } from '$app/common/interfaces/invoice';
import { EntityActionElement } from '$app/components/EntityActionElement';
import { Button } from '$app/components/forms';
import { Modal } from '$app/components/Modal';

interface Props {
  invoice: Invoice;
  isDropdown?: boolean;
}

export function EmitAsaasNfeAction(props: Props) {
  const { invoice, isDropdown = false } = props;
  const { t } = useTranslation();

  const [isModalOpen, setIsModalOpen] = useState<boolean>(false);
  const [isSubmitting, setIsSubmitting] = useState<boolean>(false);

  // Do not show on deleted invoices
  if (getEntityState(invoice) === EntityState.Deleted || invoice.is_deleted) {
    return null;
  }

  const handleEmit = () => {
    if (isSubmitting) {
      return;
    }

    setIsSubmitting(true);
    toast.processing();

    request('POST', endpoint(`/api/v1/invoices/${invoice.id}/emit_asaas_nfe`))
      .then((response) => {
        toast.success(response.data?.message ?? t('success'));
        setIsModalOpen(false);
        $refetch(['invoices']);
        $refetch(['/api/v1/invoices']);
      })
      .catch((error: AxiosError<{ message?: string; error?: string }>) => {
        const errorMsg =
          error.response?.data?.message ||
          error.response?.data?.error ||
          t('error');
        toast.error(errorMsg);
      })
      .finally(() => {
        setIsSubmitting(false);
      });
  };

  return (
    <>
      <EntityActionElement
        {...(!isDropdown && {
          key: 'emit_asaas_nfe',
        })}
        entity="invoice"
        actionKey="emit_asaas_nfe"
        isCommonActionSection={!isDropdown}
        tooltipText={t('emit_asaas_nfe')}
        onClick={() => setIsModalOpen(true)}
        icon={MdTextSnippet}
        disablePreventNavigation
      >
        {t('emit_asaas_nfe')}
      </EntityActionElement>

      <Modal
        title={`${t('emit_asaas_nfe')} - ${invoice.number}`}
        visible={isModalOpen}
        onClose={() => setIsModalOpen(false)}
        size="regular"
      >
        <div className="flex flex-col space-y-4 py-2">
          <p className="text-sm text-gray-600 dark:text-gray-300">
            {t('emit_asaas_nfe_confirm_message')}
          </p>

          <div className="flex justify-end space-x-2 pt-4">
            <Button
              type="secondary"
              onClick={() => setIsModalOpen(false)}
              disabled={isSubmitting}
            >
              {t('cancel')}
            </Button>
            <Button
              onClick={handleEmit}
              disabled={isSubmitting}
            >
              {isSubmitting ? t('processing') : t('emit_asaas_nfe')}
            </Button>
          </div>
        </div>
      </Modal>
    </>
  );
}
