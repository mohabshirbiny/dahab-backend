@php
    /** @var \App\Models\TaxInvoice $invoice */
    $seller = $invoice->party_role === \App\Enums\PartyRole::SELLER;
    $l = $invoice->lines;
    $m = fn ($v) => $v === null ? '' : number_format((float) $v, 2);
    $pct = fn ($v) => str_contains((string) $v, '.') ? rtrim(rtrim((string) $v, '0'), '.') : (string) $v;
    $weight = ($l['weight_g'] ?? null) === null ? null : $pct($l['weight_g']);
    $date = $invoice->issued_at->setTimezone('Africa/Cairo');
    $piece = ($l['piece_type_en'] ?? '').(($l['karat_label'] ?? null) ? ', '.$l['karat_label'] : '').($weight ? ', '.$weight.' g' : '');
    $pieceAr = ($l['piece_type_ar'] ?? '').(($l['karat'] ?? null) ? '، عيار '.$l['karat'] : '').($weight ? '، '.$weight.' جرام' : '');
@endphp
<html><head><meta charset="utf-8">@include('documents._style')</head><body>
@include('documents._issuer', ['issuer' => $invoice->issuer])

<table style="margin-top:6mm">
    <tr>
        <td style="width:50%"><h1>Tax invoice</h1></td>
        <td style="width:50%" class="ar" dir="rtl"><h1 lang="ar">فاتورة ضريبية</h1></td>
    </tr>
</table>

<table class="lines">
    <tr><td class="c1">Invoice number</td><td class="num c2">{{ $invoice->invoice_no }}</td><td class="ar c3" lang="ar">رقم الفاتورة</td></tr>
    <tr><td class="c1">Date</td><td class="num c2">{{ $date->format('d M Y, H:i') }}</td><td class="ar c3" lang="ar">التاريخ</td></tr>
    <tr><td class="c1">Order</td><td class="num c2">{{ substr($invoice->invoice_no, 0, -2) }}</td><td class="ar c3" lang="ar">الطلب</td></tr>
    <tr><td class="c1">{{ $seller ? 'Seller' : 'Buyer' }}</td><td class="num c2">{{ $invoice->party['full_name'] ?? '' }} ({{ $invoice->party['display_ref'] ?? '' }})</td><td class="ar c3" lang="ar">{{ $seller ? 'البائع' : 'المشتري' }}</td></tr>
    <tr><td class="c1">{{ $seller ? 'You sold' : 'You bought' }}</td><td class="num c2">{{ $piece }}<br><span lang="ar">{{ $pieceAr }}</span></td><td class="ar c3" lang="ar">{{ $seller ? 'بعت' : 'اشتريت' }}</td></tr>
</table>

@if(! empty($photo))
<p style="margin-top:5mm;text-align:center"><img src="{{ $photo }}" style="max-width:70mm;max-height:70mm"></p>
@endif

<table class="lines" style="margin-top:5mm">
    <tr class="section"><td class="c1">{{ $seller ? 'Settlement' : 'Price' }}</td><td class="num c2">EGP</td><td class="ar c3" lang="ar">{{ $seller ? 'التسوية' : 'السعر' }}</td></tr>
    @if(! $seller && ($l['price_difference'] ?? null) !== null)
        {{-- The price difference (spread) is Dahab's gap between the rates, VAT 0; the total is unchanged. --}}
        <tr><td class="c1">Gold value at {{ $m($l['seller_unit_rate']) }} per gram</td><td class="num c2">{{ $m($l['gold_value_at_seller_rate']) }}</td><td class="ar c3" lang="ar">قيمة الدهب بسعر {{ $m($l['seller_unit_rate']) }} للجرام</td></tr>
        <tr><td class="c1">Price difference (spread)</td><td class="num c2">{{ $m($l['price_difference']) }}</td><td class="ar c3" lang="ar">فرق السعر</td></tr>
        <tr><td class="c1">Making charge</td><td class="num c2">{{ $m($l['making_total']) }}</td><td class="ar c3" lang="ar">المصنعية</td></tr>
    @elseif(($l['gold_value'] ?? null) !== null)
        <tr><td class="c1">Gold value at {{ $m($l['unit_rate']) }} per gram</td><td class="num c2">{{ $m($l['gold_value']) }}</td><td class="ar c3" lang="ar">قيمة الدهب بسعر {{ $m($l['unit_rate']) }} للجرام</td></tr>
        <tr><td class="c1">Making charge</td><td class="num c2">{{ $m($l['making_total']) }}</td><td class="ar c3" lang="ar">المصنعية</td></tr>
    @else
        <tr><td class="c1">Price of the piece</td><td class="num c2">{{ $m($l['asking_price']) }}</td><td class="ar c3" lang="ar">سعر القطعة</td></tr>
    @endif
    @if(! $seller && ($l['promo_discount'] ?? null) !== null)
        {{-- A buyer code handed the spread back: the price, the discount, then what was paid. --}}
        <tr class="total"><td class="c1">Price</td><td class="num c2">{{ $m($l['price_before_discount']) }}</td><td class="ar c3" lang="ar">السعر</td></tr>
        <tr><td class="c1">Discount, code {{ $l['promo_code'] ?? '' }}</td><td class="num c2">− {{ $m($l['promo_discount']) }}</td><td class="ar c3" lang="ar">خصم الكود {{ $l['promo_code'] ?? '' }}</td></tr>
        <tr class="total"><td class="c1">Total paid</td><td class="num c2">{{ $m($l['total_paid']) }}</td><td class="ar c3" lang="ar">إجمالي المدفوع</td></tr>
    @else
    <tr class="total"><td class="c1">{{ $seller ? 'Gross' : 'Total paid' }}</td><td class="num c2">{{ $m($l['subtotal']) }}</td><td class="ar c3" lang="ar">{{ $seller ? 'الإجمالي' : 'إجمالي المدفوع' }}</td></tr>
    @endif
</table>

@if($seller)
<table class="lines" style="margin-top:5mm">
    <tr class="section"><td class="c1">Dahab charges</td><td class="num c2">EGP</td><td class="ar c3" lang="ar">رسوم دهب</td></tr>
    @if(isset($l['commission_waived']))
        @php($w = $l['commission_waived'])
        @php($why = match ($w['kind'] ?? null) { 'free_relist' => ['Free relist', 'إعادة عرض مجانية'], 'market_maker' => ['Dealer purchase', 'شراء تاجر'], default => ['Promo code', 'كود خصم'] })
        <tr><td class="c1">Commission on an ordinary sale, VAT included</td><td class="num c2">{{ $m($l['commission_ordinary_total'] ?? null) }}</td><td class="ar c3" lang="ar">العمولة في البيع العادي شاملة الضريبة</td></tr>
        <tr><td class="c1">{{ $why[0] }}{{ ! empty($w['code']) ? (($w['kind'] ?? null) === 'credit' ? ' ' : ', code ').$w['code'] : '' }}</td><td class="num c2">− {{ $m($w['amount'] ?? null) }}</td><td class="ar c3" lang="ar">{{ $why[1] }}{{ ! empty($w['code']) ? ' '.$w['code'] : '' }}</td></tr>
    @endif
    @if($l['commission_vat_inclusive'] ?? false)
    <tr><td class="c1">Commission before VAT ({{ $pct($l['commission_pct'] ?? '') }}%, minimum {{ $m($l['commission_minimum'] ?? null) }}, VAT included)</td><td class="num c2">{{ $m($invoice->net_amount) }}</td><td class="ar c3" lang="ar">العمولة قبل الضريبة ({{ $pct($l['commission_pct'] ?? '') }}٪ شاملة الضريبة)</td></tr>
    @else
    <tr><td class="c1">Commission ({{ $pct($l['commission_pct'] ?? '') }}%, minimum {{ $m($l['commission_minimum'] ?? null) }})</td><td class="num c2">{{ $m($invoice->net_amount) }}</td><td class="ar c3" lang="ar">العمولة</td></tr>
    @endif
    <tr><td class="c1">VAT at {{ $pct($invoice->vat_rate) }}% on the commission</td><td class="num c2">{{ $m($invoice->vat_amount) }}</td><td class="ar c3" lang="ar">ضريبة القيمة المضافة {{ $pct($invoice->vat_rate) }}٪ على العمولة</td></tr>
    <tr class="total"><td class="c1">{{ ($l['commission_vat_inclusive'] ?? false) ? 'Commission, VAT included' : 'Total charges' }}</td><td class="num c2">{{ $m($invoice->gross_amount) }}</td><td class="ar c3" lang="ar">إجمالي الرسوم</td></tr>
</table>
<table class="lines" style="margin-top:5mm">
    @if(isset($l['certificate_fee']))
    <tr><td class="c1">Stone certificate fee (taken from the proceeds)</td><td class="num c2">{{ $m($l['certificate_fee']) }}</td><td class="ar c3" lang="ar">رسوم شهادة الأحجار (تُخصم من المبلغ)</td></tr>
    @endif
    <tr class="total"><td class="c1">Paid to your wallet</td><td class="num c2">{{ $m($l['paid_to_wallet'] ?? null) }}</td><td class="ar c3" lang="ar">اتحول لمحفظتك</td></tr>
</table>
@else
<table class="lines" style="margin-top:5mm">
    <tr><td class="c1">VAT</td><td class="num c2">{{ $m($invoice->vat_amount) }}</td><td class="ar c3" lang="ar">ضريبة القيمة المضافة</td></tr>
    <tr class="total"><td class="c1">Invoice total</td><td class="num c2">{{ $m($invoice->gross_amount) }}</td><td class="ar c3" lang="ar">إجمالي الفاتورة</td></tr>
</table>
@endif

<p class="muted" style="margin-top:8mm">All amounts in Egyptian pounds. VAT applies to Dahab's commission only.
    <br><span lang="ar" dir="rtl">كل المبالغ بالجنيه المصري. ضريبة القيمة المضافة على عمولة دهب بس.</span></p>
</body></html>
