/**
 * Invoice Ninja (https://invoiceninja.com).
 * GestoreMio - Módulo Livro Caixa
 */

import { useQuery } from '@tanstack/react-query';
import classNames from 'classnames';
import dayjs from 'dayjs';
import { useCallback, useMemo, useState } from 'react';
import {
  ArrowDownRight,
  ArrowUpRight,
  Calendar,
  DollarSign,
  Download,
  Filter,
  PieChart,
  RefreshCw,
  Search,
  TrendingDown,
  TrendingUp,
  X,
} from 'react-feather';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { useColorScheme } from '$app/common/colors';
import { endpoint } from '$app/common/helpers';
import { request } from '$app/common/helpers/request';
import { toast } from '$app/common/helpers/toast/toast';
import { useFormatMoney } from '$app/common/hooks/money/useFormatMoney';
import { useCurrentCompany } from '$app/common/hooks/useCurrentCompany';
import { useTitle } from '$app/common/hooks/useTitle';
import { Badge } from '$app/components/Badge';
import { Card } from '$app/components/cards';
import { Default } from '$app/components/layouts/Default';
import { Spinner } from '$app/components/Spinner';

export interface CashbookItem {
  id: string;
  raw_id: number;
  type: 'income' | 'expense';
  type_label: string;
  date: string;
  amount: number;
  gross_amount: number;
  refunded: number;
  description: string;
  entity_name: string | null;
  category: string;
  reference: string;
  notes: string;
  link: string;
  balance: number;
}

export interface CashbookMeta {
  totals: {
    income: number;
    expense: number;
    net_profit: number;
    profit_margin: number;
    expense_ratio: number;
    income_count: number;
    expense_count: number;
    total_count: number;
  };
  currency: {
    code: string;
    symbol: string;
    decimal_separator: string;
    thousand_separator: string;
  };
  filters: {
    period: string;
    start_date: string | null;
    end_date: string | null;
    search: string;
    type: string;
  };
  company_name: string;
}

interface ApiResponse {
  data: CashbookItem[];
  meta: CashbookMeta;
}

type PeriodPreset =
  | '7d'
  | '14d'
  | '1m'
  | '2m'
  | '6m'
  | '1y'
  | 'all'
  | 'custom';

const PERIOD_OPTIONS: { id: PeriodPreset; label: string }[] = [
  { id: '7d', label: '7 Dias' },
  { id: '14d', label: '14 Dias' },
  { id: '1m', label: '1 Mês' },
  { id: '2m', label: '2 Meses' },
  { id: '6m', label: '6 Meses' },
  { id: '1y', label: '1 Ano' },
  { id: 'all', label: 'Tudo' },
  { id: 'custom', label: 'Personalizado' },
];

export default function Cashbook() {
  const [t] = useTranslation();
  useTitle(String(t('cashbook', { defaultValue: 'Livro Caixa' })));

  const colors = useColorScheme();
  const formatMoney = useFormatMoney();
  const company = useCurrentCompany();

  // Estados dos filtros
  const [period, setPeriod] = useState<PeriodPreset>('1m');
  const [startDate, setStartDate] = useState<string>('');
  const [endDate, setEndDate] = useState<string>('');
  const [search, setSearch] = useState<string>('');
  const [typeFilter, setTypeFilter] = useState<'all' | 'income' | 'expense'>('all');
  const [isExporting, setIsExporting] = useState<string | null>(null);

  // Paginação
  const [currentPage, setCurrentPage] = useState<number>(1);
  const pageSize = 25;

  // Montagem da query string
  const queryParams = useMemo(() => {
    const params = new URLSearchParams();
    params.set('period', period);
    if (period === 'custom') {
      if (startDate) params.set('start_date', startDate);
      if (endDate) params.set('end_date', endDate);
    }
    if (search.trim()) {
      params.set('search', search.trim());
    }
    if (typeFilter !== 'all') {
      params.set('type', typeFilter);
    }
    return params.toString();
  }, [period, startDate, endDate, search, typeFilter]);

  // Carregamento dos dados
  const {
    data: apiResponse,
    isLoading,
    isFetching,
    refetch,
  } = useQuery<ApiResponse>({
    queryKey: ['cashbook', queryParams],
    queryFn: () =>
      request('GET', endpoint(`/api/v1/cashbook?${queryParams}`)).then(
        (response) => response.data
      ),
    staleTime: 30000,
  });

  const items = apiResponse?.data ?? [];
  const meta = apiResponse?.meta;
  const totals = meta?.totals ?? {
    income: 0,
    expense: 0,
    net_profit: 0,
    profit_margin: 0,
    expense_ratio: 0,
    income_count: 0,
    expense_count: 0,
    total_count: 0,
  };

  // Itens paginados
  const totalPages = Math.ceil(items.length / pageSize) || 1;
  const paginatedItems = useMemo(() => {
    const start = (currentPage - 1) * pageSize;
    return items.slice(start, start + pageSize);
  }, [items, currentPage, pageSize]);

  // Troca de período
  const handlePeriodChange = (newPeriod: PeriodPreset) => {
    setPeriod(newPeriod);
    setCurrentPage(1);
    if (newPeriod !== 'custom') {
      setStartDate('');
      setEndDate('');
    }
  };

  // Exportação (XLSX ou PDF)
  const handleExport = useCallback(
    (format: 'xlsx' | 'pdf') => {
      setIsExporting(format);
      toast.processing();

      const exportParams = new URLSearchParams(queryParams);
      exportParams.set('format', format);

      request(
        'GET',
        endpoint(`/api/v1/cashbook/export?${exportParams.toString()}`),
        {},
        { responseType: 'blob' }
      )
        .then((response) => {
          const mimeType =
            format === 'xlsx'
              ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
              : 'application/pdf';

          const blob = new Blob([response.data], { type: mimeType });
          const url = window.URL.createObjectURL(blob);
          const link = document.createElement('a');
          link.href = url;

          const contentDisposition = response.headers['content-disposition'];
          let filename = `livro_caixa_${dayjs().format('YYYY-MM-DD')}.${format}`;
          if (contentDisposition) {
            const filenameMatch = contentDisposition.match(
              /filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/
            );
            if (filenameMatch?.[1]) {
              filename = filenameMatch[1].replace(/['"]/g, '');
            }
          }

          link.setAttribute('download', filename);
          document.body.appendChild(link);
          link.click();
          link.remove();
          window.URL.revokeObjectURL(url);

          toast.success(
            String(
              t('export_success', {
                defaultValue: `Arquivo ${format.toUpperCase()} exportado com sucesso!`,
              })
            )
          );
        })
        .catch((error) => {
          console.error('Export error:', error);
          toast.error(
            String(
              t('export_failed', {
                defaultValue: 'Não foi possível exportar o arquivo.',
              })
            )
          );
        })
        .finally(() => {
          setIsExporting(null);
        });
    },
    [queryParams, t]
  );

  // Formatação segura de moeda
  const formatVal = (val: number) => {
    return formatMoney(val, company?.settings.country_id, company?.settings.currency_id);
  };

  return (
    <Default
      title={t('cashbook', { defaultValue: 'Livro Caixa' })}
      breadcrumbs={[
        { name: t('dashboard', { defaultValue: 'Início' }), href: '/dashboard' },
        { name: t('cashbook', { defaultValue: 'Livro Caixa' }), href: '/cashbook' },
      ]}
      topRight={
        <div className="flex items-center space-x-2">
          {/* Botão de Atualizar */}
          <button
            type="button"
            onClick={() => refetch()}
            disabled={isFetching}
            className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded border transition shadow-sm hover:opacity-80"
            style={{
              borderColor: colors.$24,
              backgroundColor: colors.$1,
              color: colors.$3,
            }}
            title={String(t('refresh', { defaultValue: 'Atualizar' }))}
          >
            <RefreshCw
              size={14}
              className={classNames({ 'animate-spin': isFetching })}
            />
            <span className="hidden sm:inline">
              {t('refresh', { defaultValue: 'Atualizar' })}
            </span>
          </button>

          {/* Botão Exportar XLSX */}
          <button
            type="button"
            onClick={() => handleExport('xlsx')}
            disabled={isExporting !== null}
            className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded text-white shadow-sm transition hover:opacity-90 disabled:opacity-50"
            style={{ backgroundColor: '#15803d' }}
          >
            {isExporting === 'xlsx' ? (
              <span className="inline-flex w-3.5 h-3.5"><Spinner variant="light" /></span>
            ) : (
              <Download size={14} />
            )}
            <span>Exportar XLSX</span>
          </button>

          {/* Botão Exportar PDF */}
          <button
            type="button"
            onClick={() => handleExport('pdf')}
            disabled={isExporting !== null}
            className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded text-white shadow-sm transition hover:opacity-90 disabled:opacity-50"
            style={{ backgroundColor: '#dc2626' }}
          >
            {isExporting === 'pdf' ? (
              <span className="inline-flex w-3.5 h-3.5"><Spinner variant="light" /></span>
            ) : (
              <Download size={14} />
            )}
            <span>Exportar PDF</span>
          </button>
        </div>
      }
    >
      <div className="space-y-6">
        {/* CARDS DE RESUMO (KPIs) */}
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          {/* Card 1: Ganhos */}
          <Card
            className="p-5 shadow-sm rounded-lg border transition-all"
            style={{ borderColor: colors.$24, backgroundColor: colors.$1 }}
          >
            <div className="flex items-center justify-between">
              <div>
                <span className="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                  Total Ganhos (Entradas)
                </span>
                <div className="text-2xl font-bold mt-1 text-emerald-600 dark:text-emerald-400">
                  {formatVal(totals.income)}
                </div>
              </div>
              <div className="w-10 h-10 rounded-full flex items-center justify-center bg-emerald-100 dark:bg-emerald-950 text-emerald-600 dark:text-emerald-400">
                <ArrowUpRight size={22} />
              </div>
            </div>
            <div className="mt-3 flex items-center text-xs text-gray-500 dark:text-gray-400">
              <span className="font-medium text-emerald-600 dark:text-emerald-400 mr-1">
                {totals.income_count}
              </span>
              <span>lançamento(s) de receita</span>
            </div>
          </Card>

          {/* Card 2: Gastos */}
          <Card
            className="p-5 shadow-sm rounded-lg border transition-all"
            style={{ borderColor: colors.$24, backgroundColor: colors.$1 }}
          >
            <div className="flex items-center justify-between">
              <div>
                <span className="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                  Total Gastos (Despesas)
                </span>
                <div className="text-2xl font-bold mt-1 text-rose-600 dark:text-rose-400">
                  {formatVal(totals.expense)}
                </div>
              </div>
              <div className="w-10 h-10 rounded-full flex items-center justify-center bg-rose-100 dark:bg-rose-950 text-rose-600 dark:text-rose-400">
                <ArrowDownRight size={22} />
              </div>
            </div>
            <div className="mt-3 flex items-center text-xs text-gray-500 dark:text-gray-400">
              <span className="font-medium text-rose-600 dark:text-rose-400 mr-1">
                {totals.expense_count}
              </span>
              <span>despesa(s) registradas</span>
            </div>
          </Card>

          {/* Card 3: Lucro Líquido */}
          <Card
            className="p-5 shadow-sm rounded-lg border transition-all"
            style={{ borderColor: colors.$24, backgroundColor: colors.$1 }}
          >
            <div className="flex items-center justify-between">
              <div>
                <span className="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                  Lucro Líquido / Saldo
                </span>
                <div
                  className={classNames('text-2xl font-bold mt-1', {
                    'text-emerald-600 dark:text-emerald-400': totals.net_profit >= 0,
                    'text-rose-600 dark:text-rose-400': totals.net_profit < 0,
                  })}
                >
                  {formatVal(totals.net_profit)}
                </div>
              </div>
              <div
                className={classNames('w-10 h-10 rounded-full flex items-center justify-center', {
                  'bg-emerald-100 dark:bg-emerald-950 text-emerald-600 dark:text-emerald-400':
                    totals.net_profit >= 0,
                  'bg-rose-100 dark:bg-rose-950 text-rose-600 dark:text-rose-400':
                    totals.net_profit < 0,
                })}
              >
                {totals.net_profit >= 0 ? <TrendingUp size={22} /> : <TrendingDown size={22} />}
              </div>
            </div>
            <div className="mt-3 flex items-center text-xs text-gray-500 dark:text-gray-400">
              <span>Resultado do período apurado</span>
            </div>
          </Card>

          {/* Card 4: Margem e Índices */}
          <Card
            className="p-5 shadow-sm rounded-lg border transition-all"
            style={{ borderColor: colors.$24, backgroundColor: colors.$1 }}
          >
            <div className="flex items-center justify-between">
              <div>
                <span className="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                  Margem de Lucro
                </span>
                <div
                  className={classNames('text-2xl font-bold mt-1', {
                    'text-emerald-600 dark:text-emerald-400': totals.profit_margin >= 0,
                    'text-rose-600 dark:text-rose-400': totals.profit_margin < 0,
                  })}
                >
                  {totals.profit_margin}%
                </div>
              </div>
              <div className="w-10 h-10 rounded-full flex items-center justify-center bg-blue-100 dark:bg-blue-950 text-blue-600 dark:text-blue-400">
                <PieChart size={22} />
              </div>
            </div>

            {/* Barra de Proporção Despesas vs Receitas */}
            <div className="mt-3">
              <div className="w-full bg-gray-200 dark:bg-gray-700 h-1.5 rounded-full overflow-hidden">
                <div
                  className={classNames('h-full transition-all', {
                    'bg-emerald-500': totals.profit_margin >= 0,
                    'bg-rose-500': totals.profit_margin < 0,
                  })}
                  style={{
                    width: `${Math.min(Math.max(totals.profit_margin, 0), 100)}%`,
                  }}
                />
              </div>
              <div className="mt-1.5 flex justify-between text-[11px] text-gray-500 dark:text-gray-400">
                <span>Índice Despesas: {totals.expense_ratio}%</span>
                <span>Total: {totals.total_count} itens</span>
              </div>
            </div>
          </Card>
        </div>

        {/* BARRA DE FILTROS */}
        <Card
          className="p-4 rounded-lg shadow-sm border space-y-4"
          style={{ borderColor: colors.$24, backgroundColor: colors.$1 }}
        >
          {/* Seletor de Períodos Rápidos */}
          <div className="flex flex-wrap items-center justify-between gap-3 border-b pb-3" style={{ borderColor: colors.$24 }}>
            <div className="flex items-center gap-2 flex-wrap">
              <span className="text-xs font-semibold text-gray-500 dark:text-gray-400 flex items-center gap-1">
                <Calendar size={13} />
                <span>Período:</span>
              </span>
              {PERIOD_OPTIONS.map((opt) => (
                <button
                  key={opt.id}
                  type="button"
                  onClick={() => handlePeriodChange(opt.id)}
                  className={classNames(
                    'px-2.5 py-1 text-xs rounded-md font-medium transition',
                    {
                      'bg-primary-500 text-white shadow-xs font-semibold':
                        period === opt.id,
                      'text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800':
                        period !== opt.id,
                    }
                  )}
                  style={
                    period === opt.id
                      ? { backgroundColor: colors.$3, color: colors.$1 }
                      : {}
                  }
                >
                  {opt.label}
                </button>
              ))}
            </div>

            {/* Filtro por Tipo (Todos, Ganhos, Gastos) */}
            <div className="flex items-center rounded-lg border p-0.5" style={{ borderColor: colors.$24 }}>
              <button
                type="button"
                onClick={() => {
                  setTypeFilter('all');
                  setCurrentPage(1);
                }}
                className={classNames('px-3 py-1 text-xs rounded font-medium transition', {
                  'bg-gray-200 dark:bg-gray-700 font-semibold shadow-xs':
                    typeFilter === 'all',
                  'text-gray-500': typeFilter !== 'all',
                })}
              >
                Todos
              </button>
              <button
                type="button"
                onClick={() => {
                  setTypeFilter('income');
                  setCurrentPage(1);
                }}
                className={classNames('px-3 py-1 text-xs rounded font-medium transition', {
                  'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300 font-semibold shadow-xs':
                    typeFilter === 'income',
                  'text-gray-500': typeFilter !== 'income',
                })}
              >
                Ganhos
              </button>
              <button
                type="button"
                onClick={() => {
                  setTypeFilter('expense');
                  setCurrentPage(1);
                }}
                className={classNames('px-3 py-1 text-xs rounded font-medium transition', {
                  'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300 font-semibold shadow-xs':
                    typeFilter === 'expense',
                  'text-gray-500': typeFilter !== 'expense',
                })}
              >
                Gastos
              </button>
            </div>
          </div>

          {/* Segunda linha: Busca e Datas customizadas */}
          <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
            {/* Input de Busca */}
            <div className="relative flex-1">
              <Search
                size={16}
                className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"
              />
              <input
                type="text"
                value={search}
                onChange={(e) => {
                  setSearch(e.target.value);
                  setCurrentPage(1);
                }}
                placeholder="Pesquisar por cliente, fornecedor, descrição, categoria, notas ou referência..."
                className="w-full pl-9 pr-8 py-2 text-xs rounded-md border focus:outline-none transition shadow-2xs"
                style={{
                  borderColor: colors.$24,
                  backgroundColor: colors.$1,
                  color: colors.$3,
                }}
              />
              {search && (
                <button
                  type="button"
                  onClick={() => setSearch('')}
                  className="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                >
                  <X size={14} />
                </button>
              )}
            </div>

            {/* Inputs de Data (Personalizado ou visível para refinar) */}
            {period === 'custom' && (
              <div className="flex items-center gap-2">
                <div className="flex items-center gap-1.5">
                  <span className="text-xs text-gray-500 whitespace-nowrap">De:</span>
                  <input
                    type="date"
                    value={startDate}
                    onChange={(e) => {
                      setStartDate(e.target.value);
                      setCurrentPage(1);
                    }}
                    className="px-2.5 py-1.5 text-xs rounded-md border focus:outline-none shadow-2xs"
                    style={{
                      borderColor: colors.$24,
                      backgroundColor: colors.$1,
                      color: colors.$3,
                    }}
                  />
                </div>
                <div className="flex items-center gap-1.5">
                  <span className="text-xs text-gray-500 whitespace-nowrap">Até:</span>
                  <input
                    type="date"
                    value={endDate}
                    onChange={(e) => {
                      setEndDate(e.target.value);
                      setCurrentPage(1);
                    }}
                    className="px-2.5 py-1.5 text-xs rounded-md border focus:outline-none shadow-2xs"
                    style={{
                      borderColor: colors.$24,
                      backgroundColor: colors.$1,
                      color: colors.$3,
                    }}
                  />
                </div>
              </div>
            )}
          </div>
        </Card>

        {/* TABELA DE LANÇAMENTOS */}
        <Card
          className="rounded-lg shadow-sm border overflow-hidden"
          style={{ borderColor: colors.$24, backgroundColor: colors.$1 }}
        >
          {isLoading ? (
            <div className="py-20 flex flex-col items-center justify-center space-y-3">
              <Spinner />
              <span className="text-xs text-gray-500">
                Carregando registros do livro caixa...
              </span>
            </div>
          ) : items.length === 0 ? (
            <div className="py-16 text-center space-y-2">
              <div className="w-12 h-12 mx-auto rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center text-gray-400">
                <Filter size={20} />
              </div>
              <h3 className="text-sm font-semibold text-gray-800 dark:text-gray-200">
                Nenhum lançamento encontrado
              </h3>
              <p className="text-xs text-gray-500 max-w-sm mx-auto">
                Não há ganhos ou despesas registrados para os filtros selecionados. Tente alterar o período ou termo de busca.
              </p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-left text-xs">
                <thead>
                  <tr
                    className="border-b uppercase tracking-wider text-[11px] font-semibold"
                    style={{
                      borderColor: colors.$24,
                      backgroundColor: colors.$2,
                      color: colors.$4,
                    }}
                  >
                    <th className="py-3 px-4">Data</th>
                    <th className="py-3 px-4">Tipo</th>
                    <th className="py-3 px-4">Descrição / Contato</th>
                    <th className="py-3 px-4">Categoria / Método</th>
                    <th className="py-3 px-4">Referência / Nº</th>
                    <th className="py-3 px-4 text-right">Valor</th>
                    <th className="py-3 px-4 text-right">Saldo Acumulado</th>
                  </tr>
                </thead>
                <tbody className="divide-y" style={{ borderColor: colors.$24 }}>
                  {paginatedItems.map((item) => {
                    const isIncome = item.type === 'income';
                    return (
                      <tr
                        key={item.id}
                        className="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors"
                      >
                        {/* Data */}
                        <td className="py-3 px-4 whitespace-nowrap font-medium text-gray-700 dark:text-gray-300">
                          {dayjs(item.date).format('DD/MM/YYYY')}
                        </td>

                        {/* Tipo */}
                        <td className="py-3 px-4 whitespace-nowrap">
                          {isIncome ? (
                            <Badge variant="green">Ganho</Badge>
                          ) : (
                            <Badge variant="red">Despesa</Badge>
                          )}
                        </td>

                        {/* Descrição / Contato */}
                        <td className="py-3 px-4">
                          <div className="font-semibold text-gray-900 dark:text-gray-100">
                            {item.link ? (
                              <Link
                                to={item.link}
                                className="hover:underline transition text-inherit"
                              >
                                {item.description}
                              </Link>
                            ) : (
                              item.description
                            )}
                          </div>
                          {item.notes && item.notes !== item.description && (
                            <div className="text-[11px] text-gray-500 truncate max-w-md mt-0.5">
                              {item.notes}
                            </div>
                          )}
                        </td>

                        {/* Categoria / Método */}
                        <td className="py-3 px-4 whitespace-nowrap text-gray-600 dark:text-gray-400">
                          {item.category || '—'}
                        </td>

                        {/* Referência */}
                        <td className="py-3 px-4 whitespace-nowrap text-gray-500 font-mono text-[11px]">
                          {item.reference || '—'}
                        </td>

                        {/* Valor */}
                        <td className="py-3 px-4 whitespace-nowrap text-right font-bold text-sm">
                          <span
                            className={classNames({
                              'text-emerald-600 dark:text-emerald-400': isIncome,
                              'text-rose-600 dark:text-rose-400': !isIncome,
                            })}
                          >
                            {isIncome ? '+ ' : '- '}
                            {formatVal(item.amount)}
                          </span>
                        </td>

                        {/* Saldo Acumulado */}
                        <td className="py-3 px-4 whitespace-nowrap text-right font-bold text-xs">
                          <span
                            className={classNames({
                              'text-emerald-600 dark:text-emerald-400': item.balance >= 0,
                              'text-rose-600 dark:text-rose-400': item.balance < 0,
                            })}
                          >
                            {formatVal(item.balance)}
                          </span>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}

          {/* RODAPÉ DA TABELA / PAGINAÇÃO */}
          {!isLoading && items.length > 0 && (
            <div
              className="px-4 py-3 border-t flex flex-col sm:flex-row items-center justify-between gap-3 text-xs"
              style={{ borderColor: colors.$24, backgroundColor: colors.$1 }}
            >
              <div className="text-gray-500">
                Mostrando{' '}
                <span className="font-semibold text-gray-800 dark:text-gray-200">
                  {Math.min((currentPage - 1) * pageSize + 1, items.length)}
                </span>{' '}
                até{' '}
                <span className="font-semibold text-gray-800 dark:text-gray-200">
                  {Math.min(currentPage * pageSize, items.length)}
                </span>{' '}
                de{' '}
                <span className="font-semibold text-gray-800 dark:text-gray-200">
                  {items.length}
                </span>{' '}
                lançamentos
              </div>

              {totalPages > 1 && (
                <div className="flex items-center space-x-1">
                  <button
                    type="button"
                    onClick={() => setCurrentPage((p) => Math.max(p - 1, 1))}
                    disabled={currentPage === 1}
                    className="px-2.5 py-1 rounded border transition disabled:opacity-40"
                    style={{ borderColor: colors.$24 }}
                  >
                    Anterior
                  </button>

                  <span className="px-3 py-1 font-medium text-gray-600 dark:text-gray-400">
                    Página {currentPage} de {totalPages}
                  </span>

                  <button
                    type="button"
                    onClick={() => setCurrentPage((p) => Math.min(p + 1, totalPages))}
                    disabled={currentPage === totalPages}
                    className="px-2.5 py-1 rounded border transition disabled:opacity-40"
                    style={{ borderColor: colors.$24 }}
                  >
                    Próxima
                  </button>
                </div>
              )}
            </div>
          )}
        </Card>
      </div>
    </Default>
  );
}
