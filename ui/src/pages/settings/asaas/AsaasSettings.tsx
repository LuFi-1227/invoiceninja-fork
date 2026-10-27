/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2026. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

import { useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { CompanyGateway } from '$app/common/interfaces/company-gateway';
import { useCompanyGatewaysQuery } from '$app/common/queries/company-gateways';
import { Spinner } from '$app/components/Spinner';

export function AsaasSettings() {
  const navigate = useNavigate();
  const { data, isLoading } = useCompanyGatewaysQuery({
    status: 'active',
    perPage: '100',
  });

  const ASAAS_KEY = '07e4d88e0bbf4ad1b7dfeb21434c76b7';

  useEffect(() => {
    if (isLoading || !data) return;

    const companyGateways = data?.data?.data || [];
    const asaas = companyGateways.find(
      (cg: CompanyGateway) => cg.gateway_key === ASAAS_KEY
    );

    if (asaas) {
      navigate(`/settings/gateways/${asaas.id}/edit?tab=1`, { replace: true });
    } else {
      navigate('/settings/gateways/create', { replace: true });
    }
  }, [data, isLoading, navigate]);

  return (
    <div className="flex h-screen items-center justify-center">
      <Spinner />
    </div>
  );
}
