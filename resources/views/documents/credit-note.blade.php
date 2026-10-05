@php
    /** @var \App\Models\CreditNote $note */
    /** @var \App\Models\TaxInvoice $invoice */
    $m = fn ($v) => $v === null ? '' : number_format((float) $v, 2);
    $pct = fn ($v) => str_contains((string) $v, '.') ? rtrim(rtrim((string) $v, '0'), '.') : (string) $v;
    $date = $note->issued_at->setTimezone('Africa/Cairo');
@endphp
<html><head><meta charset="utf-8">@include('documents._style')</head><body>
@include('documents._issuer', ['issuer' => $note->issuer])

<table style="margin-top:6mm">
    <tr>
        <td style="width:50%"><h1>Credit note</h1></td>
        <td style="width:50%" class="ar" dir="rtl"><h1 lang="ar">إشعار دائن</h1></td>
    </tr>
</table>

<table class="lines">
    <tr><td class="c1">Credit note number</td><td class="num c2">{{ $note->credit_note_no }}</td><td class="ar c3" lang="ar">رقم الإشعار</td></tr>
    <tr><td class="c1">Date</td><td class="num c2">{{ $date->format('d M Y, H:i') }}</td><td class="ar c3" lang="ar">التاريخ</td></tr>
    <tr><td class="c1">Reverses invoice</td><td class="num c2">{{ $invoice->invoice_no }}</td><td class="ar c3" lang="ar">بيعكس الفاتورة</td></tr>
    <tr><td class="c1">Seller</td><td class="num c2">{{ $invoice->party['full_name'] ?? '' }} ({{ $invoice->party['display_ref'] ?? '' }})</td><td class="ar c3" lang="ar">البائع</td></tr>
    <tr><td class="c1">Why</td><td class="num c2">{{ $note->reason }}</td><td class="ar c3" lang="ar">السبب</td></tr>
</table>

<table class="lines" style="margin-top:5mm">
    <tr class="section"><td class="c1">Corrected charges</td><td class="num c2">EGP</td><td class="ar c3" lang="ar">الرسوم المصححة</td></tr>
    <tr><td class="c1">Commission</td><td class="num c2">− {{ $m($note->net_amount) }}</td><td class="ar c3" lang="ar">العمولة</td></tr>
    <tr><td class="c1">VAT at {{ $pct($invoice->vat_rate) }}%</td><td class="num c2">− {{ $m($note->vat_amount) }}</td><td class="ar c3" lang="ar">ضريبة القيمة المضافة {{ $pct($invoice->vat_rate) }}٪</td></tr>
    <tr class="total"><td class="c1">Added to your wallet</td><td class="num c2">{{ $m($note->gross_amount) }}</td><td class="ar c3" lang="ar">اتضاف لمحفظتك</td></tr>
</table>

<p class="muted" style="margin-top:8mm">An issued invoice is never changed; this credit note corrects it.
    <br><span lang="ar" dir="rtl">الفاتورة بعد ما تتصدر ما بتتعدلش؛ الإشعار ده بيصححها.</span></p>
</body></html>
