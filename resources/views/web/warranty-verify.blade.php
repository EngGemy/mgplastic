<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>تحقق ضمان {{ $warranty->code }}</title>
    <style>
        body{font-family:Tahoma,sans-serif;background:#f7f7f7;color:#111;margin:0;padding:24px}
        .card{max-width:520px;margin:0 auto;background:#fff;border:1px solid #ddd;padding:24px}
        h1{font-size:1.25rem;margin:0 0 8px}
        .status{display:inline-block;padding:4px 10px;border:1px solid #111;font-weight:700;margin:8px 0}
        table{width:100%;border-collapse:collapse;margin-top:12px}
        td,th{border:1px solid #ccc;padding:8px;text-align:right;font-size:.9rem}
        a.btn{display:inline-block;margin-top:16px;padding:12px 16px;background:#111;color:#fff;text-decoration:none}
    </style>
</head>
<body>
<div class="card">
    <h1>MG للصناعات البلاستيكية</h1>
    <p>شهادة ضمان</p>
    <p><strong>{{ $warranty->code }}</strong></p>
    <div class="status">{{ $data['status'] }}</div>
    <p>السباك: {{ $data['plumber']['name'] ?? '—' }} @if(!empty($data['plumber']['level'])) ({{ $data['plumber']['level'] }}) @endif</p>
    <p>هاتف العميل: {{ $data['customer_phone_masked'] }}</p>
    <p>من {{ $data['issued_at'] }} حتى {{ $data['expires_at'] }}</p>
    <table>
        <tr><th>المنتج</th><th>كمية</th><th>أشهر</th></tr>
        @foreach($data['items'] as $item)
            <tr>
                <td>{{ $item['name'] }}</td>
                <td>{{ $item['quantity'] }}</td>
                <td>{{ $item['warranty_months'] }}</td>
            </tr>
        @endforeach
    </table>
    @if($warranty->status === 'active')
        <a class="btn" href="#claim">تقديم مطالبة</a>
        <form id="claim" method="post" action="{{ url('/w/'.$warranty->code.'/claims') }}" style="margin-top:16px">
            @csrf
            <label>آخر 4 أرقام من الهاتف<br><input name="phone_last4" maxlength="4" required style="width:100%;padding:8px;margin:6px 0"></label>
            <label>الملاحظة<br><textarea name="note" required style="width:100%;padding:8px;margin:6px 0" rows="3"></textarea></label>
            <button type="submit" style="width:100%;padding:12px;background:#111;color:#fff;border:0">إرسال المطالبة</button>
        </form>
    @endif
</div>
</body>
</html>
