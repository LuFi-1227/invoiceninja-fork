<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\Expense;
use App\Models\Payment;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CashbookController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Resolve date boundaries based on period and custom dates.
     */
    protected function resolveDates(Request $request): array
    {
        $period = $request->input('period', '1m');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        if ($period && $period !== 'custom') {
            $today = Carbon::today();
            switch ($period) {
                case '7d':
                    $startDate = $today->copy()->subDays(6)->toDateString();
                    $endDate = $today->toDateString();
                    break;
                case '14d':
                    $startDate = $today->copy()->subDays(13)->toDateString();
                    $endDate = $today->toDateString();
                    break;
                case '1m':
                    $startDate = $today->copy()->subDays(30)->toDateString();
                    $endDate = $today->toDateString();
                    break;
                case '2m':
                    $startDate = $today->copy()->subDays(60)->toDateString();
                    $endDate = $today->toDateString();
                    break;
                case '6m':
                    $startDate = $today->copy()->subDays(180)->toDateString();
                    $endDate = $today->toDateString();
                    break;
                case '1y':
                    $startDate = $today->copy()->subDays(365)->toDateString();
                    $endDate = $today->toDateString();
                    break;
                case 'all':
                    $startDate = null;
                    $endDate = null;
                    break;
            }
        }

        return [$startDate, $endDate, $period];
    }

    /**
     * Safely resolve the active company.
     */
    protected function resolveCompany(Request $request): \App\Models\Company
    {
        $user = $request->user();
        if ($user) {
            try {
                return $user->company();
            } catch (\Throwable $e) {
                // Fallback to relations
            }

            $firstCompany = $user->companies()->first();
            if ($firstCompany) {
                return $firstCompany;
            }
        }

        $first = \App\Models\Company::first();
        if ($first) {
            return $first;
        }

        throw new \Exception('Nenhuma empresa encontrada no sistema.');
    }

    /**
     * Fetch raw data, calculate totals and running balance.
     */
    protected function getCashbookData(Request $request): array
    {
        $company = $this->resolveCompany($request);
        [$startDate, $endDate, $period] = $this->resolveDates($request);
        $search = trim($request->input('search', ''));
        $type = $request->input('type', 'all'); // 'all', 'income', 'expense'

        // Obter timezone da empresa para garantir horários locais exatos
        $tz = 'America/Sao_Paulo';
        if ($company) {
            try {
                $tz = $company->timezone()->name ?? 'America/Sao_Paulo';
            } catch (\Throwable $e) {
                $tz = 'America/Sao_Paulo';
            }
        }

        // Função utilitária para converter timestamps (Carbon, Unix timestamp int ou string) para Carbon com timezone
        $resolveCarbon = function ($val) use ($tz) {
            if (empty($val)) {
                return null;
            }
            if ($val instanceof \Carbon\Carbon) {
                return $val->copy()->setTimezone($tz);
            }
            if (is_numeric($val)) {
                return \Carbon\Carbon::createFromTimestamp($val, $tz);
            }
            try {
                return \Carbon\Carbon::parse($val)->setTimezone($tz);
            } catch (\Throwable $e) {
                return null;
            }
        };

        $transactions = [];

        // 1. Ganhos / Entradas (Payments)
        if ($type !== 'expense') {
            $paymentsQuery = Payment::query()
                ->where('company_id', $company->id)
                ->where('is_deleted', false)
                ->whereNotIn('status_id', [Payment::STATUS_CANCELLED, Payment::STATUS_FAILED])
                ->with(['client:id,name', 'type:id,name']);

            if ($startDate) {
                $paymentsQuery->where('date', '>=', $startDate);
            }
            if ($endDate) {
                $paymentsQuery->where('date', '<=', $endDate);
            }
            if (!empty($search)) {
                $paymentsQuery->where(function ($q) use ($search) {
                    $q->where('number', 'like', "%{$search}%")
                        ->orWhere('transaction_reference', 'like', "%{$search}%")
                        ->orWhere('private_notes', 'like', "%{$search}%")
                        ->orWhereHas('client', function ($cq) use ($search) {
                            $cq->where('name', 'like', "%{$search}%");
                        });
                });
            }

            $payments = $paymentsQuery->get();

            foreach ($payments as $payment) {
                $netAmount = (float) ($payment->amount - $payment->refunded);
                
                // Resolver data e hora de chegada com timezone da empresa
                $createdTz = $resolveCarbon($payment->created_at);
                $date = $payment->date ? substr($payment->date, 0, 10) : ($createdTz ? $createdTz->format('Y-m-d') : date('Y-m-d'));
                $time = $createdTz ? $createdTz->format('H:i:s') : '00:00:00';
                $datetime = $date . ' ' . $time;

                $transactions[] = [
                    'id' => 'income_' . $payment->id,
                    'raw_id' => $payment->id,
                    'type' => 'income',
                    'type_label' => 'Ganho',
                    'date' => $date,
                    'datetime' => $datetime,
                    'time' => substr($time, 0, 5),
                    'created_at' => $createdTz ? $createdTz->toDateTimeString() : $datetime,
                    'amount' => $netAmount,
                    'gross_amount' => (float) $payment->amount,
                    'refunded' => (float) $payment->refunded,
                    'description' => $payment->client ? $payment->client->name : 'Pagamento de Cliente',
                    'entity_name' => $payment->client ? $payment->client->name : 'Cliente Avulso',
                    'category' => $payment->type ? $payment->type->name : 'Pagamento',
                    'reference' => $payment->number ?: ($payment->transaction_reference ?: ('#' . $payment->id)),
                    'notes' => $payment->private_notes ?: '',
                    'link' => '/payments/' . $payment->id . '/edit',
                ];
            }
        }

        // 2. Gastos / Despesas (Expenses)
        if ($type !== 'income') {
            $expensesQuery = Expense::query()
                ->where('company_id', $company->id)
                ->where('is_deleted', false)
                ->with(['vendor:id,name', 'category:id,name', 'payment_type:id,name']);

            if ($startDate) {
                $expensesQuery->where('date', '>=', $startDate);
            }
            if ($endDate) {
                $expensesQuery->where('date', '<=', $endDate);
            }
            if (!empty($search)) {
                $expensesQuery->where(function ($q) use ($search) {
                    $q->where('number', 'like', "%{$search}%")
                        ->orWhere('transaction_reference', 'like', "%{$search}%")
                        ->orWhere('public_notes', 'like', "%{$search}%")
                        ->orWhere('private_notes', 'like', "%{$search}%")
                        ->orWhereHas('vendor', function ($vq) use ($search) {
                            $vq->where('name', 'like', "%{$search}%");
                        })
                        ->orWhereHas('category', function ($cq) use ($search) {
                            $cq->where('name', 'like', "%{$search}%");
                        });
                });
            }

            $expenses = $expensesQuery->get();

            foreach ($expenses as $expense) {
                $amount = (float) $expense->amount;

                // Resolver data e hora de chegada com timezone da empresa
                $createdTz = $resolveCarbon($expense->created_at);
                $date = $expense->date ? substr($expense->date, 0, 10) : ($expense->payment_date ? substr($expense->payment_date, 0, 10) : ($createdTz ? $createdTz->format('Y-m-d') : date('Y-m-d')));
                $time = $createdTz ? $createdTz->format('H:i:s') : '00:00:00';
                $datetime = $date . ' ' . $time;

                $desc = $expense->vendor ? $expense->vendor->name : ($expense->category ? $expense->category->name : ($expense->public_notes ?: 'Despesa'));
                $cat = $expense->category ? $expense->category->name : ($expense->payment_type ? $expense->payment_type->name : 'Despesa');

                $transactions[] = [
                    'id' => 'expense_' . $expense->id,
                    'raw_id' => $expense->id,
                    'type' => 'expense',
                    'type_label' => 'Despesa',
                    'date' => $date,
                    'datetime' => $datetime,
                    'time' => substr($time, 0, 5),
                    'created_at' => $createdTz ? $createdTz->toDateTimeString() : $datetime,
                    'amount' => $amount,
                    'gross_amount' => $amount,
                    'refunded' => 0.0,
                    'description' => $desc,
                    'entity_name' => $expense->vendor ? $expense->vendor->name : null,
                    'category' => $cat,
                    'reference' => $expense->number ?: ($expense->transaction_reference ?: ('#' . $expense->id)),
                    'notes' => $expense->public_notes ?: ($expense->private_notes ?: ''),
                    'link' => '/expenses/' . $expense->id . '/edit',
                ];
            }
        }

        // Ordenar cronologicamente crescente por data e horário exato de chegada para calcular saldo acumulado (running balance)
        usort($transactions, function ($a, $b) {
            $cmp = strcmp($a['datetime'], $b['datetime']);
            if ($cmp === 0) {
                return $a['raw_id'] <=> $b['raw_id'];
            }
            return $cmp;
        });

        $runningBalance = 0.0;
        $totalIncome = 0.0;
        $totalExpense = 0.0;
        $incomeCount = 0;
        $expenseCount = 0;

        foreach ($transactions as &$item) {
            if ($item['type'] === 'income') {
                $runningBalance += $item['amount'];
                $totalIncome += $item['amount'];
                $incomeCount++;
            } else {
                $runningBalance -= $item['amount'];
                $totalExpense += $item['amount'];
                $expenseCount++;
            }
            $item['balance'] = round($runningBalance, 2);
        }
        unset($item);

        // Inverter para apresentação (mais recente primeiro)
        $itemsDescending = array_reverse($transactions);

        $netProfit = $totalIncome - $totalExpense;
        $profitMargin = $totalIncome > 0 ? round(($netProfit / $totalIncome) * 100, 2) : 0;
        $expenseRatio = $totalIncome > 0 ? round(($totalExpense / $totalIncome) * 100, 2) : ($totalExpense > 0 ? 100 : 0);

        $currencyId = $company->settings->currency_id ?? $company->currency_id ?? 20;
        $currency = Currency::find($currencyId) ?: Currency::find(20);

        return [
            'items' => $itemsDescending,
            'items_asc' => $transactions,
            'totals' => [
                'income' => round($totalIncome, 2),
                'expense' => round($totalExpense, 2),
                'net_profit' => round($netProfit, 2),
                'profit_margin' => $profitMargin,
                'expense_ratio' => $expenseRatio,
                'income_count' => $incomeCount,
                'expense_count' => $expenseCount,
                'total_count' => count($transactions),
            ],
            'currency' => [
                'code' => $currency->code ?? 'BRL',
                'symbol' => $currency->symbol ?? 'R$',
                'decimal_separator' => $currency->decimal_separator ?? ',',
                'thousand_separator' => $currency->thousand_separator ?? '.',
            ],
            'filters' => [
                'period' => $period,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'search' => $search,
                'type' => $type,
            ],
            'company_name' => $company->settings->name ?? ($company->name ?? 'GestoreMio'),
        ];
    }

    /**
     * Endpoint API para listagem e estatísticas.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $this->getCashbookData($request);

        return response()->json([
            'data' => $data['items'],
            'meta' => [
                'totals' => $data['totals'],
                'currency' => $data['currency'],
                'filters' => $data['filters'],
                'company_name' => $data['company_name'],
            ],
        ]);
    }

    /**
     * Exportação para XLSX ou PDF.
     */
    public function export(Request $request)
    {
        $format = strtolower($request->input('format', 'xlsx'));
        $data = $this->getCashbookData($request);

        if ($format === 'xlsx' || $format === 'excel') {
            return $this->exportXlsx($data);
        }

        return $this->exportPdf($data);
    }

    /**
     * Gera planilha XLSX estilizada com PhpSpreadsheet.
     */
    protected function exportXlsx(array $data): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Livro Caixa');

        $currencySymbol = $data['currency']['symbol'];

        // Estilos gerais
        $sheet->setShowGridLines(true);

        // 1. Título e cabeçalho da empresa
        $sheet->mergeCells('A1:H1');
        $sheet->setCellValue('A1', mb_strtoupper($data['company_name']) . ' - LIVRO CAIXA');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFF'));
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('1E293B');
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(32);

        // 2. Subtítulo com período e data de geração
        $sheet->mergeCells('A2:H2');
        $periodInfo = 'Período: ' . ($data['filters']['start_date'] ? Carbon::parse($data['filters']['start_date'])->format('d/m/Y') : 'Início') . ' até ' . ($data['filters']['end_date'] ? Carbon::parse($data['filters']['end_date'])->format('d/m/Y') : 'Hoje') . ' | Emitido em: ' . date('d/m/Y H:i:s');
        $sheet->setCellValue('A2', $periodInfo);
        $sheet->getStyle('A2')->getFont()->setSize(10)->setItalic(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('64748B'));
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension(2)->setRowHeight(20);

        // 3. Bloco de Resumo (KPIs)
        $sheet->setCellValue('A4', 'Total Ganhos:');
        $sheet->setCellValue('B4', $data['totals']['income']);
        $sheet->setCellValue('C4', 'Total Gastos:');
        $sheet->setCellValue('D4', $data['totals']['expense']);
        $sheet->setCellValue('E4', 'Lucro Líquido:');
        $sheet->setCellValue('F4', $data['totals']['net_profit']);
        $sheet->setCellValue('G4', 'Margem:');
        $sheet->setCellValue('H4', $data['totals']['profit_margin'] . '%');

        $sheet->getStyle('A4:H4')->getFont()->setBold(true);
        $sheet->getStyle('B4')->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('16A34A'));
        $sheet->getStyle('D4')->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('DC2626'));
        $sheet->getStyle('F4')->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($data['totals']['net_profit'] >= 0 ? '16A34A' : 'DC2626'));
        $sheet->getStyle('B4')->getNumberFormat()->setFormatCode('"R$ "#,##0.00');
        $sheet->getStyle('D4')->getNumberFormat()->setFormatCode('"R$ "#,##0.00');
        $sheet->getStyle('F4')->getNumberFormat()->setFormatCode('"R$ "#,##0.00');
        $sheet->getStyle('A4:H4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('F1F5F9');

        // 4. Cabeçalho da Tabela
        $row = 6;
        $headers = [
            'A' => 'Data / Horário',
            'B' => 'Tipo',
            'C' => 'Descrição / Contato',
            'D' => 'Categoria / Forma',
            'E' => 'Referência / Nº',
            'F' => 'Entrada (R$)',
            'G' => 'Saída (R$)',
            'H' => 'Saldo Acumulado (R$)',
        ];

        foreach ($headers as $col => $title) {
            $sheet->setCellValue($col . $row, $title);
        }

        $sheet->getStyle("A{$row}:H{$row}")->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFF'));
        $sheet->getStyle("A{$row}:H{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('0F172A');
        $sheet->getStyle("A{$row}:H{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension($row)->setRowHeight(24);

        // 5. Linhas de Dados
        $row++;
        foreach ($data['items'] as $item) {
            $dateFormatted = Carbon::parse($item['datetime'] ?? $item['date'])->format('d/m/Y H:i');
            $sheet->setCellValue("A{$row}", $dateFormatted);
            $sheet->setCellValue("B{$row}", $item['type_label']);
            $sheet->setCellValue("C{$row}", $item['description']);
            $sheet->setCellValue("D{$row}", $item['category']);
            $sheet->setCellValue("E{$row}", $item['reference']);

            if ($item['type'] === 'income') {
                $sheet->setCellValue("F{$row}", $item['amount']);
                $sheet->setCellValue("G{$row}", 0);
                $sheet->getStyle("F{$row}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('16A34A'))->setBold(true);
            } else {
                $sheet->setCellValue("F{$row}", 0);
                $sheet->setCellValue("G{$row}", $item['amount']);
                $sheet->getStyle("G{$row}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('DC2626'))->setBold(true);
            }

            $sheet->setCellValue("H{$row}", $item['balance']);
            $sheet->getStyle("H{$row}")->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($item['balance'] >= 0 ? '16A34A' : 'DC2626'));

            $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("B{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("E{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("F{$row}:H{$row}")->getNumberFormat()->setFormatCode('"R$ "#,##0.00');

            // Zebra styling
            if ($row % 2 === 0) {
                $sheet->getStyle("A{$row}:H{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('F8FAFC');
            }

            $row++;
        }

        // 6. Linha Totalizadora
        $sheet->setCellValue("A{$row}", 'TOTAIS');
        $sheet->mergeCells("A{$row}:E{$row}");
        $sheet->setCellValue("F{$row}", $data['totals']['income']);
        $sheet->setCellValue("G{$row}", $data['totals']['expense']);
        $sheet->setCellValue("H{$row}", $data['totals']['net_profit']);

        $sheet->getStyle("A{$row}:H{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}:H{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('E2E8F0');
        $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("F{$row}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('16A34A'));
        $sheet->getStyle("G{$row}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('DC2626'));
        $sheet->getStyle("H{$row}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($data['totals']['net_profit'] >= 0 ? '16A34A' : 'DC2626'));
        $sheet->getStyle("F{$row}:H{$row}")->getNumberFormat()->setFormatCode('"R$ "#,##0.00');
        $sheet->getRowDimension($row)->setRowHeight(24);

        // Bordas
        $styleArray = [
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => 'CBD5E1'],
                ],
            ],
        ];
        $sheet->getStyle("A6:H{$row}")->applyFromArray($styleArray);

        // Ajuste automático de largura de colunas
        foreach (range('A', 'H') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = 'livro_caixa_' . date('Ymd_His') . '.xlsx';

        return new StreamedResponse(
            function () use ($spreadsheet) {
                $writer = new Xlsx($spreadsheet);
                $writer->save('php://output');
            },
            200,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control' => 'max-age=0',
            ]
        );
    }

    /**
     * Gera relatório PDF estilizado com Dompdf.
     */
    protected function exportPdf(array $data)
    {
        $companyName = htmlspecialchars($data['company_name'], ENT_QUOTES, 'UTF-8');
        $startDate = $data['filters']['start_date'] ? Carbon::parse($data['filters']['start_date'])->format('d/m/Y') : 'Início';
        $endDate = $data['filters']['end_date'] ? Carbon::parse($data['filters']['end_date'])->format('d/m/Y') : 'Hoje';
        $now = date('d/m/Y H:i');

        $fmt = function ($val) {
            return 'R$ ' . number_format($val, 2, ',', '.');
        };

        $rowsHtml = '';
        foreach ($data['items'] as $item) {
            $date = Carbon::parse($item['datetime'] ?? $item['date'])->format('d/m/Y H:i');
            $isIncome = $item['type'] === 'income';
            $badgeColor = $isIncome ? '#16a34a' : '#dc2626';
            $badgeBg = $isIncome ? '#dcfce7' : '#fee2e2';
            $amountColor = $isIncome ? '#16a34a' : '#dc2626';
            $prefix = $isIncome ? '+' : '-';
            $balanceColor = $item['balance'] >= 0 ? '#16a34a' : '#dc2626';

            $desc = htmlspecialchars($item['description'], ENT_QUOTES, 'UTF-8');
            $cat = htmlspecialchars($item['category'], ENT_QUOTES, 'UTF-8');
            $ref = htmlspecialchars($item['reference'], ENT_QUOTES, 'UTF-8');

            $rowsHtml .= "
                <tr>
                    <td style=\"text-align:center;\">{$date}</td>
                    <td style=\"text-align:center;\">
                        <span style=\"display:inline-block; padding:2px 8px; font-size:10px; font-weight:bold; border-radius:12px; background:{$badgeBg}; color:{$badgeColor};\">
                            {$item['type_label']}
                        </span>
                    </td>
                    <td><strong>{$desc}</strong></td>
                    <td>{$cat}</td>
                    <td style=\"text-align:center;\">{$ref}</td>
                    <td style=\"text-align:right; color:{$amountColor}; font-weight:bold;\">{$prefix} {$fmt($item['amount'])}</td>
                    <td style=\"text-align:right; color:{$balanceColor}; font-weight:bold;\">{$fmt($item['balance'])}</td>
                </tr>
            ";
        }

        $html = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset=\"utf-8\">
            <title>Livro Caixa - {$companyName}</title>
            <style>
                @page { size: A4 landscape; margin: 15mm; }
                body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 11px; color: #1e293b; margin: 0; padding: 0; }
                .header { border-bottom: 2px solid #0f172a; padding-bottom: 12px; margin-bottom: 16px; }
                .title { font-size: 20px; font-weight: bold; color: #0f172a; margin: 0; }
                .subtitle { font-size: 11px; color: #64748b; margin-top: 4px; }
                .kpi-container { width: 100%; margin-bottom: 16px; }
                .kpi-table { width: 100%; border-collapse: separate; border-spacing: 8px 0; }
                .kpi-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px; text-align: center; }
                .kpi-label { font-size: 10px; color: #64748b; text-transform: uppercase; font-weight: bold; margin-bottom: 4px; }
                .kpi-val { font-size: 15px; font-weight: bold; }
                .green { color: #16a34a; }
                .red { color: #dc2626; }
                .table-main { width: 100%; border-collapse: collapse; margin-top: 10px; }
                .table-main th { background: #0f172a; color: #ffffff; padding: 7px 6px; font-size: 10px; text-transform: uppercase; font-weight: bold; border: 1px solid #0f172a; }
                .table-main td { padding: 6px; border: 1px solid #e2e8f0; font-size: 10px; }
                .table-main tr:nth-child(even) { background-color: #f8fafc; }
                .footer-row { background: #e2e8f0 !important; font-weight: bold; }
                .footer-row td { border-top: 2px solid #94a3b8; }
                .print-footer { position: fixed; bottom: -10mm; left: 0; right: 0; text-align: center; font-size: 9px; color: #94a3b8; }
            </style>
        </head>
        <body>
            <div class=\"header\">
                <table style=\"width:100%;\">
                    <tr>
                        <td>
                            <div class=\"title\">{$companyName} - LIVRO CAIXA</div>
                            <div class=\"subtitle\">Período: {$startDate} até {$endDate} | Emitido em: {$now}</div>
                        </td>
                        <td style=\"text-align:right;\">
                            <span style=\"font-size:12px; font-weight:bold; color:#0f172a;\">GestoreMio</span>
                        </td>
                    </tr>
                </table>
            </div>

            <div class=\"kpi-container\">
                <table class=\"kpi-table\">
                    <tr>
                        <td class=\"kpi-card\">
                            <div class=\"kpi-label\">Total Ganhos ({$data['totals']['income_count']})</div>
                            <div class=\"kpi-val green\">{$fmt($data['totals']['income'])}</div>
                        </td>
                        <td class=\"kpi-card\">
                            <div class=\"kpi-label\">Total Gastos ({$data['totals']['expense_count']})</div>
                            <div class=\"kpi-val red\">{$fmt($data['totals']['expense'])}</div>
                        </td>
                        <td class=\"kpi-card\">
                            <div class=\"kpi-label\">Lucro Líquido</div>
                            <div class=\"kpi-val " . ($data['totals']['net_profit'] >= 0 ? 'green' : 'red') . "\">{$fmt($data['totals']['net_profit'])}</div>
                        </td>
                        <td class=\"kpi-card\">
                            <div class=\"kpi-label\">Margem de Lucro</div>
                            <div class=\"kpi-val " . ($data['totals']['profit_margin'] >= 0 ? 'green' : 'red') . "\">{$data['totals']['profit_margin']}%</div>
                        </td>
                        <td class=\"kpi-card\">
                            <div class=\"kpi-label\">Índice de Gastos</div>
                            <div class=\"kpi-val\">{$data['totals']['expense_ratio']}%</div>
                        </td>
                    </tr>
                </table>
            </div>

            <table class=\"table-main\">
                <thead>
                    <tr>
                        <th style=\"width:105px;\">Data / Horário</th>
                        <th style=\"width:65px;\">Tipo</th>
                        <th>Descrição / Contato</th>
                        <th>Categoria / Forma</th>
                        <th style=\"width:90px;\">Referência</th>
                        <th style=\"width:105px; text-align:right;\">Valor</th>
                        <th style=\"width:110px; text-align:right;\">Saldo Acumulado</th>
                    </tr>
                </thead>
                <tbody>
                    {$rowsHtml}
                    <tr class=\"footer-row\">
                        <td colspan=\"5\" style=\"text-align:center;\">TOTAIS DO PERÍODO ({$data['totals']['total_count']} lançamentos)</td>
                        <td style=\"text-align:right; color:" . ($data['totals']['net_profit'] >= 0 ? '#16a34a' : '#dc2626') . "; font-size:11px;\">{$fmt($data['totals']['net_profit'])}</td>
                        <td style=\"text-align:right; color:" . ($data['totals']['net_profit'] >= 0 ? '#16a34a' : '#dc2626') . "; font-size:11px;\">{$fmt($data['totals']['net_profit'])}</td>
                    </tr>
                </tbody>
            </table>

            <div class=\"print-footer\">
                Documento gerado automaticamente pelo sistema GestoreMio - Página gerada em {$now}
            </div>
        </body>
        </html>
        ";

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        $filename = 'livro_caixa_' . date('Ymd_His') . '.pdf';

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Sincronizar transações da Asaas diretamente a partir do Livro Caixa.
     * POST /api/v1/cashbook/sync_asaas
     * Suporta: mode = 'both' | 'income' | 'expense'
     */
    public function syncAsaas(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        [$startDate, $endDate, $period] = $this->resolveDates($request);

        $mode = $request->input('mode', 'both'); // 'both', 'income', 'expense'
        $allTime = ($period === 'all');

        $options = [
            'start_date' => $startDate,
            'finish_date' => $endDate,
            'all_time' => $allTime,
            'auto_create_client' => true,
            'auto_create_vendor' => true,
        ];

        $results = [
            'success' => true,
            'company_id' => $company->id,
            'mode' => $mode,
            'period' => $period,
            'income' => null,
            'expense' => null,
        ];

        // Sincronizar Ganhos (Incomes / Recebimentos)
        if ($mode === 'both' || $mode === 'income') {
            $incomeService = \App\Services\Asaas\AsaasIncomeService::forCompany($company);
            $incomeResult = $incomeService->sync($options);
            $results['income'] = $incomeResult;
            if (! ($incomeResult['success'] ?? false)) {
                $results['success'] = false;
            }
        }

        // Sincronizar Débitos / Despesas (Expenses)
        if ($mode === 'both' || $mode === 'expense') {
            $expenseService = \App\Services\Asaas\AsaasCardExpenseService::forCompany($company);
            $expenseResult = $expenseService->sync($options);
            $results['expense'] = $expenseResult;
            if (! ($expenseResult['success'] ?? false)) {
                $results['success'] = false;
            }
        }

        $totalCreated = ($results['income']['created'] ?? 0) + ($results['expense']['created'] ?? 0);
        $totalSkipped = ($results['income']['skipped'] ?? 0) + ($results['expense']['skipped'] ?? 0);
        $incomeCreated = $results['income']['created'] ?? 0;
        $expenseCreated = $results['expense']['created'] ?? 0;

        $results['message'] = "Sincronização Asaas concluída: {$incomeCreated} ganhos e {$expenseCreated} despesas criadas ({$totalSkipped} já sincronizados anteriormente).";

        return response()->json($results, $results['success'] ? 200 : 207);
    }
}
