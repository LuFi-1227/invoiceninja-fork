@component('email.template.client', ['design' => 'light', 'settings' => $settings, 'logo' => $logo, 'company' => $company ?? ''])
    <div class="center">
        @isset($greeting)
            <p>{{ $greeting }}</p>
        @endisset

        <h1>Nota Fiscal Emitida</h1>

        <div style="margin-top: 15px; margin-bottom: 25px; line-height: 1.6; text-align: left; background: #f9fafb; padding: 15px; border-radius: 6px; border: 1px solid #e5e7eb;">
            <p style="margin: 5px 0;"><strong>Fatura:</strong> #{{ $invoice->number }}</p>
            @if(!empty($nf_number))
                <p style="margin: 5px 0;"><strong>Número da NFS-e:</strong> {{ $nf_number }}</p>
            @endif
            @if(!empty($validation_code))
                <p style="margin: 5px 0;"><strong>Código de Verificação:</strong> {{ $validation_code }}</p>
            @endif
            <p style="margin: 5px 0;"><strong>Valor:</strong> R$ {{ number_format($value, 2, ',', '.') }}</p>
            @if(!empty($payment_method))
                <p style="margin: 5px 0;"><strong>Forma de Pagamento:</strong> {{ $payment_method }}</p>
            @endif
        </div>

        <p style="margin-bottom: 25px;">Sua Nota Fiscal de Serviço (NFS-e) foi emitida com sucesso pelo Asaas e o arquivo PDF segue em anexo a este e-mail.</p>

        @if(!empty($url))
        <div style="margin: 25px 0;">
            <table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation">
                <tbody>
                    <tr>
                        <td align="center" style="border-radius: 4px; background-color: #2563eb;">
                            <a href="{{ $url }}" target="_blank" style="text-decoration: none; border: 1px solid #2563eb; display: inline-block; border-radius: 4px; padding: 12px 25px; font-size: 16px; font-weight: bold; color: #ffffff;">
                                Visualizar / Baixar Nota Fiscal (PDF)
                            </a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        @endif

        @if(!empty($xml_url))
            <p style="font-size: 13px; color: #6b7280; margin-top: 15px;">
                Você também pode baixar o arquivo XML da NFS-e <a href="{{ $xml_url }}" target="_blank" style="color: #2563eb;">clicando aqui</a>.
            </p>
        @endif

        @isset($signature)
            <div style="margin-top: 30px;">
                <p>{!! nl2br($signature) !!}</p>
            </div>
        @endisset
    </div>
@endcomponent
