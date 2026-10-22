/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2022. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

import { useAtom } from 'jotai';
import { useTranslation } from 'react-i18next';
import { useDispatch, useSelector } from 'react-redux';
import { useOutletContext } from 'react-router-dom';
import { useCurrentCompany } from '$app/common/hooks/useCurrentCompany';
import { useCurrentUser } from '$app/common/hooks/useCurrentUser';
import { ValidationBag } from '$app/common/interfaces/validation-bag';
import { updateChanges } from '$app/common/stores/slices/user';
import { RootState } from '$app/common/stores/store';
import { CustomField } from '$app/components/CustomField';
import { MarkdownEditor } from '$app/components/forms/MarkdownEditor';
import { LanguageSelector } from '$app/components/LanguageSelector';
import { Element } from '../../../../components/cards';
import { InputField, SelectField } from '../../../../components/forms';
import Toggle from '$app/components/forms/Toggle';
import { hasLanguageChanged } from '../../localization/common/atoms';

export function Details() {
  const [t] = useTranslation();

  const errors: ValidationBag = useOutletContext();

  const user = useCurrentUser();

  const dispatch = useDispatch();

  const company = useCurrentCompany();

  const [, setHasLanguageIdChanged] = useAtom(hasLanguageChanged);

  const userChanges = useSelector((state: RootState) => state.user.changes);

  const handleChange = (property: string, value: string | number | boolean) => {
    dispatch(
      updateChanges({
        property: property,
        value: value,
      })
    );
  };

  return (
    <>
      {userChanges && (
        <>
          <Element leftSide={t('first_name')}>
            <InputField
              value={userChanges?.first_name || user?.first_name || ''}
              onValueChange={(value) => handleChange('first_name', value)}
              errorMessage={(errors?.errors?.first_name ?? [])[0]}
            />
          </Element>

          <Element leftSide={t('last_name')}>
            <InputField
              value={userChanges?.last_name || user?.last_name || ''}
              onValueChange={(value) => handleChange('last_name', value)}
              errorMessage={(errors?.errors?.last_name ?? [])[0]}
            />
          </Element>

          <Element leftSide={t('email')}>
            <InputField
              value={userChanges?.email || user?.email || ''}
              type="email"
              onValueChange={(value) => handleChange('email', value)}
              errorMessage={(errors?.errors?.email ?? [])[0]}
            />
          </Element>

          <Element leftSide={t('language')}>
            <LanguageSelector
              value={userChanges?.language_id || ''}
              onChange={(v) => {
                setHasLanguageIdChanged(true);
                handleChange('language_id', v);
              }}
              errorMessage={(errors?.errors?.language_id ?? [])[0]}
              dismissable
            />
          </Element>

          <Element leftSide={t('phone')}>
            <InputField
              value={userChanges?.phone || user?.phone || ''}
              onValueChange={(value) => handleChange('phone', value)}
              errorMessage={(errors?.errors?.phone ?? [])[0]}
            />
          </Element>

          <Element
            leftSide={t('contact_method') || 'Forma de contato'}
            leftSideHelp={
              t('contact_method_help') ||
              'Canal utilizado como guia na cobrança recorrente'
            }
          >
            <SelectField
              value={
                userChanges?.contact_method ||
                user?.contact_method ||
                'whatsapp_and_email'
              }
              onValueChange={(value) => handleChange('contact_method', value)}
              errorMessage={(errors?.errors?.contact_method ?? [])[0]}
            >
              <option value="whatsapp_and_email">
                {t('whatsapp_and_email') || 'WhatsApp e E-mail'}
              </option>
              <option value="whatsapp">
                {t('only_whatsapp') || 'Só WhatsApp'}
              </option>
              <option value="email">
                {t('only_email') || 'Só E-mail'}
              </option>
            </SelectField>
          </Element>

          <Element
            leftSide={t('emit_nota_fiscal') || 'Emitir Nota Fiscal'}
            leftSideHelp={
              t('emit_nota_fiscal_help') ||
              'Emitir Nota Fiscal (Asaas) para cobranças vinculadas a este usuário'
            }
          >
            <Toggle
              checked={
                userChanges?.emit_nota_fiscal ?? user?.emit_nota_fiscal ?? true
              }
              onChange={(value) => handleChange('emit_nota_fiscal', value)}
            />
          </Element>

          <Element leftSide={t('signature')}>
            <MarkdownEditor
              value={userChanges?.signature || ''}
              onChange={(value) => handleChange('signature', value)}
            />
          </Element>

          {company?.custom_fields?.user1 && (
            <CustomField
              field="user1"
              defaultValue={userChanges.custom_value1}
              value={company.custom_fields.user1}
              onValueChange={(value) => handleChange('custom_value1', value)}
            />
          )}

          {company?.custom_fields?.user2 && (
            <CustomField
              field="user2"
              defaultValue={userChanges.custom_value2}
              value={company.custom_fields.user2}
              onValueChange={(value) => handleChange('custom_value2', value)}
            />
          )}

          {company?.custom_fields?.user3 && (
            <CustomField
              field="user3"
              defaultValue={userChanges.custom_value3}
              value={company.custom_fields.user3}
              onValueChange={(value) => handleChange('custom_value3', value)}
            />
          )}

          {company?.custom_fields?.user4 && (
            <CustomField
              field="user4"
              defaultValue={userChanges.custom_value4}
              value={company.custom_fields.user4}
              onValueChange={(value) => handleChange('custom_value4', value)}
            />
          )}
        </>
      )}
    </>
  );
}
