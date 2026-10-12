/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2022. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

import classNames from 'classnames';
import { useAtomValue } from 'jotai';
import { FormEvent, ReactElement, ReactNode, useState } from 'react';
import { Info, Menu as MenuIcon } from 'react-feather';
import toast from 'react-hot-toast';
import { useTranslation } from 'react-i18next';
import { useColorScheme } from '$app/common/colors';
import { trans } from '$app/common/helpers';
import { useCurrentCompanyUser } from '$app/common/hooks/useCurrentCompanyUser';
import { useReactSettings } from '$app/common/hooks/useReactSettings';
import { Invoice } from '$app/common/interfaces/invoice';
import { useSocketEvent } from '$app/common/queries/sockets';
import { Breadcrumbs, Page } from '$app/components/Breadcrumbs';
import { Dropdown } from '$app/components/dropdown/Dropdown';
import { DropdownElement } from '$app/components/dropdown/DropdownElement';
import { Button, Link } from '$app/components/forms';
import {
  saveBtnAtom,
  useNavigationTopRightElement,
} from '$app/components/layouts/common/hooks';
import { QuickCreatePopover } from '$app/components/QuickCreatePopover';
import { Search } from '$app/pages/dashboard/components/Search';
import CommonProps from '../../common/interfaces/common-props.interface';
import { ActivateCompany } from '../banners/ActivateCompany';
import { EInvoiceCredits } from '../banners/EInvoiceCredits';
import { PriceIncreaseBanner } from '../banners/PriceIncrease';
import { VerifyEmail } from '../banners/VerifyEmail';
import { VerifyPhone } from '../banners/VerifyPhone';
import { Feedback } from '../Feedback';
import { Notifications } from '../Notifications';
import { AccountPlanExpired } from '../banners/AccountPlanExpired';
import { useNavigation } from './common/navigation';
import { DesktopSidebar } from './components/DesktopSidebar';
import { MobileSidebar } from './components/MobileSidebar';

export interface SaveOption {
  label: string;
  onClick: (event: FormEvent<HTMLFormElement>) => unknown;
  icon?: ReactElement;
}

interface Props extends CommonProps {
  title?: string | null;
  onSaveClick?: any;
  onCancelClick?: any;
  breadcrumbs: Page[];
  topRight?: ReactNode;
  docsLink?: string;
  navigationTopRight?: ReactNode;
  saveButtonLabel?: string | null;
  disableSaveButton?: boolean;
  additionalSaveOptions?: SaveOption[];
  aboveMainContainer?: ReactNode;
  afterBreadcrumbs?: ReactNode;
}

export function Default(props: Props) {
  const [t] = useTranslation();

  const colors = useColorScheme();

  const companyUser = useCurrentCompanyUser();
  const reactSettings = useReactSettings();

  const isMiniSidebar = Boolean(reactSettings.show_mini_sidebar);

  const [sidebarOpen, setSidebarOpen] = useState<boolean>(false);

  const navigation = useNavigation();

  const saveBtn = useAtomValue(saveBtnAtom);
  const navigationTopRightElement = useNavigationTopRightElement();

  useSocketEvent<Invoice>({
    on: ['App\\Events\\Invoice\\InvoiceWasViewed'],
    callback: ({ data }) => {
      if (
        !companyUser?.notifications.email.includes('invoice_viewed') ||
        !companyUser?.notifications.email.includes('invoice_viewed_user')
      ) {
        return;
      }

      toast(
        <div className="flex flex-col gap-2">
          <span className="flex items-center gap-1">
            <Info size={18} />
            <span>
              {trans('notification_invoice_viewed_subject', {
                invoice: data.number,
                client: data.client?.display_name,
              })}
              .
            </span>
          </span>

          <div className="flex justify-center">
            <Link to={`/invoices/${data.id}/edit`}>{t('view_invoice')}</Link>
          </div>
        </div>,
        {
          duration: 8000,
          position: 'top-center',
        }
      );
    },
  });

  return (
    <div>
      <div className="fixed bottom-4 right-4 z-50 flex items-end flex-col-reverse space-y-4 space-y-reverse">
        <ActivateCompany />
        <VerifyEmail />
        <VerifyPhone />
        <EInvoiceCredits />
        <AccountPlanExpired />

        {/* This component is only created for December 2025 if you see it in 2026 you can remove and delete it */}
        <PriceIncreaseBanner />
      </div>

      <MobileSidebar
        navigation={navigation}
        sidebarOpen={sidebarOpen}
        setSidebarOpen={setSidebarOpen}
      />

      <DesktopSidebar navigation={navigation} docsLink={props.docsLink} />

      <div
        className={classNames('flex flex-col flex-1', {
          'md:pl-16': isMiniSidebar,
          'md:pl-64': !isMiniSidebar,
        })}
      >
        <div
          style={{ backgroundColor: colors.$1 }}
          className="sticky top-0 z-10 flex-shrink-0 flex h-16 border-b shadow"
        >
          <button
            type="button"
            className="px-4 border-r border-gray-200 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-indigo-500 md:hidden"
            onClick={() => setSidebarOpen(true)}
          >
            <span className="sr-only">Open sidebar</span>
            <MenuIcon color={colors.$3} />
          </button>

          <div
            className="flex-1 px-4 xl:px-8 flex items-center"
            data-cy="topNavbar"
          >
            <div className="flex flex-1 items-center space-x-4">
              <h2
                style={{ color: colors.$3 }}
                className="text-sm md:text-lg whitespace-nowrap"
              >
                {props.title}
              </h2>

              <QuickCreatePopover />
              <Search />
            </div>

            <div className="ml-4 flex items-center md:ml-6 space-x-2 lg:space-x-3">
              <Notifications />

              {props.onCancelClick && (
                <Button onClick={props.onCancelClick} type="secondary">
                  {t('cancel')}
                </Button>
              )}

              {(Boolean(props.onSaveClick) || saveBtn) && (
                <div>
                  {!props.additionalSaveOptions && (
                    <Button
                      onClick={saveBtn?.onClick || props.onSaveClick}
                      disabled={
                        saveBtn?.disableSaveButton || props.disableSaveButton
                      }
                      disableWithoutIcon
                    >
                      {(saveBtn?.label || props.saveButtonLabel) ?? t('save')}
                    </Button>
                  )}

                  {props.additionalSaveOptions && (
                    <div className="flex">
                      <Button
                        className="rounded-br-none rounded-tr-none px-3"
                        onClick={saveBtn?.onClick || props.onSaveClick}
                        disabled={
                          saveBtn?.disableSaveButton || props.disableSaveButton
                        }
                        disableWithoutIcon
                      >
                        {(saveBtn?.label || props.saveButtonLabel) ?? t('save')}
                      </Button>

                      <Dropdown
                        className="rounded-bl-none rounded-tl-none h-full px-1 border-l-1 border-y-0 border-r-0"
                        cardActions
                        disabled={
                          saveBtn?.disableSaveButton || props.disableSaveButton
                        }
                        labelButtonBorderColor={colors.$1}
                      >
                        {props.additionalSaveOptions.map((option, index) => (
                          <DropdownElement
                            key={index}
                            icon={option.icon}
                            disabled={props.disableSaveButton}
                            onClick={option.onClick}
                          >
                            {option.label}
                          </DropdownElement>
                        ))}
                      </Dropdown>
                    </div>
                  )}
                </div>
              )}

              {(navigationTopRightElement || props.navigationTopRight) && (
                <div className="flex space-x-3 items-center">
                  {navigationTopRightElement?.element ||
                    props.navigationTopRight}
                </div>
              )}
            </div>
          </div>
        </div>

        {props.aboveMainContainer}

        <main className="flex-1">
          {(props.breadcrumbs || props.topRight || props.afterBreadcrumbs) &&
            props.breadcrumbs.length > 0 && (
              <div className="pt-4 px-4 md:px-6 md:pt-6 dark:text-gray-100 flex flex-col lg:flex-row lg:justify-between lg:items-center space-y-4 lg:space-y-0">
                <div className="flex items-center w-full">
                  {props.breadcrumbs && (
                    <Breadcrumbs pages={props.breadcrumbs} />
                  )}

                  {props.afterBreadcrumbs}
                </div>

                {props.topRight && <div>{props.topRight}</div>}
              </div>
            )}

          <div
            style={{ color: colors.$3, backgroundColor: colors.$23 }}
            className="p-4 xl:px-6 dark:text-gray-100"
          >
            {props.children}
          </div>
        </main>
      </div>

      <Feedback />
    </div>
  );
}
