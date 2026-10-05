@php($i = $issuer ?? [])
<table class="head">
    <tr>
        <td style="width:50%">
            <b>{{ $i['legal_name_en'] ?? '' }}</b><br>
            <span class="muted">{{ $i['address_en'] ?? '' }}<br>
            Tax registration no. {{ $i['tax_registration_no'] ?? '' }} · Commercial register no. {{ $i['commercial_register_no'] ?? '' }}</span>
        </td>
        <td style="width:50%" class="ar" dir="rtl">
            <b lang="ar">{{ $i['legal_name_ar'] ?? '' }}</b><br>
            <span class="muted" lang="ar">{{ $i['address_ar'] ?? '' }}<br>
            رقم التسجيل الضريبي {{ $i['tax_registration_no'] ?? '' }} · رقم السجل التجاري {{ $i['commercial_register_no'] ?? '' }}</span>
        </td>
    </tr>
</table>
