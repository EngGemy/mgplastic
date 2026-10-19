<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $company }} — افتح التطبيق</title>
    <style>
        :root { --ink:#0d2f6e; --accent:#1a56c8; --bg:#f3f6fc; --card:#fff; }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; font-family: Tahoma, "Segoe UI", sans-serif;
            background: linear-gradient(160deg, #0a2858, #1a56c8 55%, #e8f0ff);
            color: var(--ink); display: flex; align-items: center; justify-content: center;
            padding: 24px;
        }
        .card {
            width: min(420px, 100%); background: var(--card); border-radius: 20px;
            padding: 28px 24px; box-shadow: 0 18px 50px rgba(10,40,90,.25); text-align: center;
        }
        h1 { font-size: 1.25rem; margin: 0 0 8px; }
        p { color: #5a6d8c; margin: 0 0 20px; line-height: 1.6; font-size: .95rem; }
        .btn {
            display: block; width: 100%; margin: 10px 0; padding: 14px 16px; border-radius: 12px;
            text-decoration: none; font-weight: 700; border: 0; cursor: pointer;
        }
        .btn-primary { background: var(--accent); color: #fff; }
        .btn-secondary { background: #e8eef8; color: var(--ink); }
        .meta { margin-top: 16px; font-size: .75rem; color: #8a9bb5; word-break: break-all; }
        .hidden { display: none; }
    </style>
</head>
<body>
<div class="card">
    <h1>{{ $company }}</h1>
    <p>جاري فتح التطبيق على الشاشة المناسبة…</p>

    <div id="actions" class="hidden">
        <a class="btn btn-primary" id="open-btn" href="#">افتح في التطبيق</a>
        @if($isAndroid && $androidStore)
            <a class="btn btn-secondary" href="{{ $androidStore }}">تحميل من Google Play</a>
        @elseif($isIos && $iosStore)
            <a class="btn btn-secondary" href="{{ $iosStore }}">تحميل من App Store</a>
        @else
            @if($androidStore)
                <a class="btn btn-secondary" href="{{ $androidStore }}">Android</a>
            @endif
            @if($iosStore)
                <a class="btn btn-secondary" href="{{ $iosStore }}">iOS</a>
            @endif
        @endif
    </div>

    <div class="meta">{{ $type }}/{{ $id }}</div>
</div>
<script>
(function () {
    var isAndroid = @json($isAndroid);
    var isIos = @json($isIos);
    var customUrl = @json($customUrl);
    var intentUrl = @json($intentUrl);
    var openBtn = document.getElementById('open-btn');
    var actions = document.getElementById('actions');

    var target = isAndroid ? intentUrl : customUrl;
    openBtn.href = target;

    try {
        window.location.href = target;
    } catch (e) {}

    setTimeout(function () {
        actions.classList.remove('hidden');
    }, 1500);
})();
</script>
</body>
</html>
