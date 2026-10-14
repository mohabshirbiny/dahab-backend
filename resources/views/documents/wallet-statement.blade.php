@php
    /** @var array<string, mixed> $s  BuildCustomerStatementAction */
    $m = fn ($v) => $v === null ? '' : number_format((float) $v, 2);
    $day = fn (string $d) => \Illuminate\Support\Carbon::parse($d, 'Africa/Cairo')->format('d M Y');
    $name = $s['customer']['full_name'] ?: ('Customer '.$s['customer']['display_ref']);
@endphp
<html><head><meta charset="utf-8">@include('documents._style')
<style>
    .rows td { border-bottom: 0.2mm solid #e4dfd6; font-size: 8.5pt; padding: 1.2mm 1mm; }
    .rows tr.head td { background: #f5f1ea; font-weight: bold; }
</style>
</head><body>
@if($issuer)
    @include('documents._issuer', ['issuer' => $issuer])
@else
    <b>Dahab</b> <span lang="ar">· دهب</span>
@endif

<table style="margin-top:6mm">
    <tr>
        <td style="width:50%"><h1>Wallet statement</h1></td>
        <td style="width:50%" class="ar" dir="rtl"><h1 lang="ar">كشف حساب المحفظة</h1></td>
    </tr>
</table>

<table class="lines">
    <tr><td class="c1">Name</td><td class="num c2">{{ $name }}</td><td class="ar c3" lang="ar">الاسم</td></tr>
    <tr><td class="c1">Customer number</td><td class="num c2">{{ $s['customer']['display_ref'] }}</td><td class="ar c3" lang="ar">رقم العميل</td></tr>
    <tr><td class="c1">Period</td><td class="num c2">{{ $day($s['from']) }} – {{ $day($s['to']) }}</td><td class="ar c3" lang="ar">الفترة</td></tr>
</table>

<table class="lines" style="margin-top:5mm">
    <tr class="section"><td class="c1">Summary</td><td class="num c2">EGP</td><td class="ar c3" lang="ar">الملخص</td></tr>
    <tr><td class="c1">Available at the start</td><td class="num c2">{{ $m($s['opening']['available']) }}</td><td class="ar c3" lang="ar">المتاح في الأول</td></tr>
    <tr><td class="c1">Money in</td><td class="num c2">{{ $m($s['money_in']) }}</td><td class="ar c3" lang="ar">فلوس دخلت</td></tr>
    <tr><td class="c1">Money out (holds included)</td><td class="num c2">{{ $m($s['money_out']) }}</td><td class="ar c3" lang="ar">فلوس خرجت (مع الحجز)</td></tr>
    <tr class="total"><td class="c1">Available at the end</td><td class="num c2">{{ $m($s['closing']['available']) }}</td><td class="ar c3" lang="ar">المتاح في الآخر</td></tr>
    <tr><td class="c1">Held at the end (still yours)</td><td class="num c2">{{ $m($s['closing']['held']) }}</td><td class="ar c3" lang="ar">المحجوز في الآخر (لسه بتاعك)</td></tr>
</table>

<table class="rows" style="margin-top:6mm">
    <tr class="head">
        <td style="width:16%">Date <span lang="ar">التاريخ</span></td>
        <td style="width:40%">Movement <span lang="ar">الحركة</span></td>
        <td style="width:14%">Reference <span lang="ar">المرجع</span></td>
        <td style="width:15%" class="num">Amount <span lang="ar">المبلغ</span></td>
        <td style="width:15%" class="num">Available after <span lang="ar">المتاح بعدها</span></td>
    </tr>
    @forelse($s['rows'] as $r)
        <tr>
            <td>{{ \Illuminate\Support\Carbon::parse($r['created_at'])->setTimezone('Africa/Cairo')->format('d M Y, H:i') }}</td>
            <td>{{ $r['label_en'] }}<br><span lang="ar">{{ $r['label_ar'] }}</span></td>
            <td>{{ $r['reference'] ?? '—' }}</td>
            <td class="num">{{ $m($r['amount']) }}</td>
            <td class="num">{{ $m($r['available_after']) }}</td>
        </tr>
    @empty
        <tr><td colspan="5">No movements in this period. <span lang="ar">مفيش حركات في الفترة دي.</span></td></tr>
    @endforelse
</table>

@if($s['truncated'])
    <p class="muted">This statement shows the first {{ count($s['rows']) }} movements of the period. Choose a shorter period to see the rest.
        <br><span lang="ar" dir="rtl">الكشف ده فيه أول {{ count($s['rows']) }} حركة في الفترة. اختار فترة أقصر عشان تشوف الباقي.</span></p>
@endif

<p class="muted" style="margin-top:8mm">All amounts in Egyptian pounds. A hold is shown as money out of your available balance; it is still yours until the order finishes.
    <br><span lang="ar" dir="rtl">كل المبالغ بالجنيه المصري. الحجز بيظهر كفلوس خرجت من المتاح؛ وهو لسه بتاعك لحد ما الطلب يخلص.</span></p>
</body></html>
