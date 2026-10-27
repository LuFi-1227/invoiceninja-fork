<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2026. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\DataMapper;

use Illuminate\Support\Facades\App;

class EmailTemplateDefaults
{
    public array $templates = [
        'email_template_invoice',
        'email_template_quote',
        'email_template_credit',
        'email_template_payment',
        'email_template_payment_partial',
        'email_template_statement',
        'email_template_reminder1',
        'email_template_reminder2',
        'email_template_reminder3',
        'email_template_reminder_endless',
        'email_template_custom1',
        'email_template_custom2',
        'email_template_custom3',
        'email_template_purchase_order',
        'email_template_payment_failed',
    ];

    public static function getDefaultTemplate($template, $locale)
    {
        App::setLocale($locale);

        switch ($template) {
            /* Template */

            case 'email_template_payment_failed':
                return self::emailPaymentFailedTemplate();
            case 'email_template_invoice':
                return self::emailInvoiceTemplate();
            case 'email_template_quote':
                return self::emailQuoteTemplate();
            case 'email_template_credit':
                return self::emailCreditTemplate();
            case 'email_template_payment':
                return self::emailPaymentTemplate();
            case 'email_template_payment_partial':
                return self::emailPaymentPartialTemplate();
            case 'email_template_statement':
                return self::emailStatementTemplate();
            case 'email_template_reminder1':
                return self::emailReminder1Template();
            case 'email_template_reminder2':
                return self::emailReminder2Template();
            case 'email_template_reminder3':
                return self::emailReminder3Template();
            case 'email_template_reminder_endless':
                return self::emailReminderEndlessTemplate();
            case 'email_template_custom1':
                return self::emailInvoiceTemplate();
            case 'email_template_custom2':
                return self::emailInvoiceTemplate();
            case 'email_template_custom3':
                return self::emailInvoiceTemplate();
            case 'email_template_purchase_order':
                return self::emailPurchaseOrderTemplate();
                /* Subject */
            case 'email_subject_purchase_order':
                return self::emailPurchaseOrderSubject();
            case 'email_subject_invoice':
                return self::emailInvoiceSubject();

            case 'email_subject_payment_failed':
                return self::emailPaymentFailedSubject();

            case 'email_subject_quote':
                return self::emailQuoteSubject();

            case 'email_subject_credit':
                return self::emailCreditSubject();

            case 'email_subject_payment':
                return self::emailPaymentSubject();

            case 'email_subject_payment_partial':
                return self::emailPaymentPartialSubject();

            case 'email_subject_statement':
                return self::emailStatementSubject();

            case 'email_subject_reminder1':
                return self::emailReminder1Subject();

            case 'email_subject_reminder2':
                return self::emailReminder2Subject();

            case 'email_subject_reminder3':
                return self::emailReminder3Subject();

            case 'email_subject_reminder_endless':
                return self::emailReminderEndlessSubject();

            case 'email_subject_custom1':
                return self::emailInvoiceSubject();

            case 'email_subject_custom2':
                return self::emailInvoiceSubject();

            case 'email_subject_custom3':
                return self::emailInvoiceSubject();

            case 'email_vendor_notification_subject':
                return self::emailVendorNotificationSubject();

            case 'email_vendor_notification_body':
                return self::emailVendorNotificationBody();

            case 'email_quote_template_reminder1':
                return self::emailQuoteReminder1Body();

            case 'email_quote_subject_reminder1':
                return self::emailQuoteReminder1Subject();

            default:
                return self::emailInvoiceTemplate();

        }
    }

    public static function emailPaymentFailedSubject()
    {
        return ctrans('texts.notification_invoice_payment_failed_subject', ['invoice' => '$number']);
    }

    public static function emailPaymentFailedTemplate()
    {
        return '<p>$client<br><br>' . ctrans('texts.client_payment_failure_body', ['invoice' => '$number', 'amount' => '$amount']) . '</p><div>$payment_error</div><br><div>$view_button</div>';
    }

    public static function emailQuoteReminder1Subject()
    {
        return ctrans('texts.quote_reminder_subject', ['quote' => '$number', 'company' => '$company.name']);
    }

    public static function emailQuoteReminder1Body()
    {

        return '<p>$client<br><br>' . self::transformText('quote_reminder_message') . '</p><div>$view_button</div>';

    }

    public static function emailVendorNotificationSubject()
    {
        return self::transformText('vendor_notification_subject');
    }

    public static function emailVendorNotificationBody()
    {
        return self::transformText('vendor_notification_body');
    }

    public static function emailInvoiceSubject()
    {
        return 'Fatura #$number emitida - $company.name';
    }

    public static function emailCreditSubject()
    {
        return ctrans('texts.credit_subject', ['number' => '$number', 'account' => '$company.name']);
    }

    public static function emailInvoiceTemplate()
    {
        return self::buildBrandedTemplate(
            'SUA FATURA FOI EMITIDA',
            'Passando para informar que sua fatura referente aos serviços contratados foi emitida com vencimento para <strong>$due_date</strong>.'
        );
    }

    public static function emailInvoiceReminderTemplate()
    {
        return self::buildBrandedTemplate(
            'SUA FATURA VENCE EM BREVE',
            'Passando para lembrar que sua fatura referente aos serviços contratados vence em <strong>$due_date</strong>.'
        );
    }

    public static function emailQuoteSubject()
    {
        return ctrans('texts.quote_subject', ['number' => '$number', 'account' => '$company.name']);
    }

    public static function emailQuoteTemplate()
    {
        $quote_message = '<p>$client<br><br>' . self::transformText('quote_message') . '</p><div>$view_button</div>';

        return $quote_message;
    }

    public static function emailPaymentSubject()
    {
        return ctrans('texts.payment_subject');
    }

    public static function emailPurchaseOrderSubject()
    {
        return ctrans('texts.purchase_order_subject', ['number' => '$number', 'account' => '$account']);
    }

    public static function emailPurchaseOrderTemplate()
    {
        $purchase_order_message = '<p>$vendor<br><br>' . self::transformText('purchase_order_message') . '</p><div>$view_button</div>';

        return $purchase_order_message;
    }

    public static function emailPaymentTemplate()
    {
        $payment_message = '<p>$client<br><br>' . self::transformText('payment_message') . '<br><br>$invoices</p><div>$view_button</div>';

        return $payment_message;
    }

    public static function emailCreditTemplate()
    {
        $credit_message = '<p>$client<br><br>' . self::transformText('credit_message') . '</p><div>$view_button</div>';

        return $credit_message;
    }

    public static function emailPaymentPartialTemplate()
    {
        $payment_message = '<p>$client<br><br>' . self::transformText('payment_message') . '<br><br>$invoices</p><div>$view_button</div>';

        return $payment_message;
    }

    public static function emailPaymentPartialSubject()
    {
        return ctrans('texts.payment_subject');
    }

    public static function emailReminder1Subject()
    {
        return 'Lembrete: Fatura #$number vence em 7 dias - $company.name';
    }

    public static function emailReminder1Template()
    {
        return self::buildBrandedTemplate(
            'SUA FATURA VENCE EM 7 DIAS',
            'Passando para lembrar que sua fatura referente aos serviços contratados vence em <strong>$due_date</strong> (daqui a 7 dias).'
        );
    }

    public static function emailReminder2Subject()
    {
        return 'Lembrete: Fatura #$number vence em 3 dias - $company.name';
    }

    public static function emailReminder2Template()
    {
        return self::buildBrandedTemplate(
            'SUA FATURA VENCE EM 3 DIAS',
            'Passando para lembrar que sua fatura referente aos serviços contratados vence em <strong>$due_date</strong> (daqui a 3 dias).'
        );
    }

    public static function emailReminder3Subject()
    {
        return 'Aviso: Fatura #$number vence hoje - $company.name';
    }

    public static function emailReminder3Template()
    {
        return self::buildBrandedTemplate(
            'SUA FATURA VENCE HOJE',
            'Lembramos que sua fatura referente aos serviços contratados vence <strong>hoje ($due_date)</strong>.'
        );
    }

    public static function emailReminderEndlessSubject()
    {
        return 'Aviso de Cobrança: Fatura #$number pendente - $company.name';
    }

    public static function emailReminderEndlessTemplate()
    {
        return self::buildBrandedTemplate(
            'AVISO DE FATURA PENDENTE',
            'Constatamos que sua fatura com vencimento em <strong>$due_date</strong> ainda não teve o pagamento identificado. Acesse o portal abaixo para quitar ou emitir a 2ª via atualizada.'
        );
    }

    public static function buildBrandedTemplate(string $stageTitle, string $introText): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$stageTitle} - \$company.name</title>
</head>
<body style="margin:0; padding:0; background-color:\$theme_bg_color; font-family:Arial, Helvetica, sans-serif; color:#252525;">
  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:\$theme_bg_color; border-collapse:collapse;">
    <tr>
      <td align="center" style="padding:0 12px;">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="728" style="width:100%; max-width:728px; background:\$theme_card_color; border-collapse:collapse;">

          <!-- Cabeçalho com cor dinâmica -->
          <tr>
            <td style="background:\$theme_header_bg; padding:28px 34px; color:#ffffff;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
                <tr>
                  <td valign="middle" style="font-size:22px; line-height:1.3; font-weight:bold; color:#ffffff;">
                    {$stageTitle}
                  </td>
                  <td align="right" valign="middle" style="width:190px; padding-left:16px;">
                    \$company_logo_tag
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Saudação e resumo -->
          <tr>
            <td style="padding:40px 38px 24px; font-size:17px; line-height:1.45;">
              <p style="margin:0 0 10px;">Olá, <strong>\$contact</strong>! Tudo bem?</p>
              <p style="margin:0;">{$introText}</p>
            </td>
          </tr>

          <!-- Detalhes da fatura -->
          <tr>
            <td style="padding:12px 38px 0;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #17376f; border-collapse:collapse;">
                <tr>
                  <td style="padding:20px 23px 24px; font-size:16px; line-height:1.65;">
                    <div><strong>Fatura:</strong> #\$number</div>
                    <div><strong>Data de emissão:</strong> \$date</div>
                    <div><strong>Data de vencimento:</strong> \$due_date</div>
                  </td>
                </tr>
                <tr>
                  <td style="background:\$theme_header_bg; color:#ffffff; padding:20px 24px; font-size:24px; line-height:1.25; font-weight:bold;">
                    Valor da Fatura: \$amount
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Pagamento -->
          <tr>
            <td style="background:\$theme_payment_bg; padding:32px 24px 28px; text-align:center;">
              <div style="font-size:20px; line-height:1.4; font-weight:bold; margin-bottom:7px; color:\$theme_accent_color;">Como pagar sua fatura?</div>
              <div style="font-size:16px; line-height:1.4; margin-bottom:24px;">Use o código de barras ou a chave Pix abaixo.</div>
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate; border-spacing:0;">
                <tr>
                  <td width="50%" valign="top" style="background:#ffffff; border-radius:12px; padding:20px 22px; text-align:left;">
                    <div style="font-size:18px; font-weight:bold; color:\$theme_accent_color; text-align:center; margin-bottom:14px;">Código de barras</div>
                    <div style="font-size:14px; line-height:1.35; overflow-wrap:anywhere; word-break:break-word; font-family:monospace; color:#333333;">
                      \$asaas_linha_digitavel
                    </div>
                  </td>
                  <td width="8" style="font-size:0; line-height:0;">&nbsp;</td>
                  <td width="50%" valign="top" style="background:#ffffff; border-radius:12px; padding:20px 22px; text-align:left;">
                    <div style="font-size:18px; font-weight:bold; color:\$theme_accent_color; text-align:center; margin-bottom:14px;">Chave Pix Copia e Cola</div>
                    <div style="font-size:13px; line-height:1.35; overflow-wrap:anywhere; word-break:break-word; font-family:monospace; color:#333333;">
                      \$asaas_pix_payload
                    </div>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Botão de segunda via / pagamento -->
          <tr>
            <td align="center" style="padding:24px 24px 12px;">
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;">
                <tr>
                  <td align="center" bgcolor="\$theme_button_bg" style="border-radius:30px; background-color:\$theme_button_bg;">
                    <a href="\$view_url" target="_blank"
                       style="display:inline-block; padding:16px 48px; color:#ffffff; font-size:18px; line-height:1.2; font-weight:bold; text-decoration:none; border-radius:30px; background-color:\$theme_button_bg;">
                      Acessar Fatura e 2ª Via
                    </a>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Mensagem final -->
          <tr>
            <td style="padding:24px 38px 20px; font-size:15px; line-height:1.5;">
              <p style="margin:0 0 20px;"><strong>Lembre-se:</strong> mantenha o pagamento em dia para evitar juros e suspensão dos serviços!</p>
              <p style="margin:0 0 24px; color:#666666;">Caso já tenha realizado o pagamento, por favor desconsidere esta mensagem.</p>
              <p style="margin:0 0 4px;"><strong>Um abraço,</strong></p>
              <p style="margin:0 0 24px;">Equipe \$company.name</p>

              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse; color:#60778a; font-size:12px; line-height:1.5;">
                <tr>
                  <td valign="top" style="padding-right:18px;">
                    Dúvidas sobre esta cobrança? Fale com nosso suporte financeiro:<br>
                    ✉ <a href="mailto:\$company.email" style="color:#0088a8; text-decoration:none;">\$company.email</a>
                    &nbsp;|&nbsp; \$company.phone
                  </td>
                  <td valign="top" align="left" style="width:170px;">
                    <strong>\$company.name · \$company.city</strong><br>
                    <a href="https://\$company.website" target="_blank" style="color:#0088a8; text-decoration:none;">\$company.website</a>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Rodapé escuro -->
          <tr>
            <td style="background:\$theme_footer_bg; padding:22px 30px; color:#ffffff;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
                <tr>
                  <td valign="middle" style="width:205px; padding-right:20px;">
                    \$company_logo_tag
                  </td>
                  <td valign="middle" style="font-size:12px; line-height:1.6; padding-right:12px; color:#dddddd;">
                    Consultoria em TI<br>
                    Aplicativos<br>
                    Atendimento Automatizado
                  </td>
                  <td valign="middle" style="font-size:12px; line-height:1.6; padding-right:12px; color:#dddddd;">
                    Criação de Sites<br>
                    Hospedagem<br>
                    Landing Pages
                  </td>
                  <td valign="middle" align="right" style="font-size:16px; white-space:nowrap; color:#dddddd;">
                    <span style="display:inline-block; border:1px solid #dddddd; border-radius:50%; width:24px; height:24px; line-height:24px; text-align:center;">◉</span>
                    <span style="display:inline-block; border:1px solid #dddddd; border-radius:50%; width:24px; height:24px; line-height:24px; text-align:center;">◎</span>
                    <span style="display:inline-block; border:1px solid #dddddd; border-radius:50%; width:24px; height:24px; line-height:24px; text-align:center;">f</span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td align="center" style="background:#dddddd; padding:8px 12px; color:#333333; font-size:11px;">
              © 2026 \$company.name. Todos os direitos reservados.
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }

    public static function emailStatementSubject()
    {
        return ctrans('texts.your_statement');
    }

    public static function emailStatementTemplate()
    {
        $statement_message = '<p>$client<br><br>' . self::transformText('client_statement_body') . '<br></p>';

        return $statement_message;

        // return ctrans('texts.client_statement_body', ['start_date' => '$start_date', 'end_date' => '$end_date']);
    }

    private static function transformText($string)
    {
        //preformat the string, removing trailing colons.

        return str_replace(':', '$', rtrim(ctrans('texts.' . $string), ':'));
    }
}
