<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $company }} — افتح التطبيق</title>
    {{-- iOS Smart App Banner (optional once App Store ID known) --}}
    @if(!empty($iosStore) && preg_match('/id(\d+)/', $iosStore, $m))
        <meta name="apple-itunes-app" content="app-id={{ $m[1] }}, app-argument={{ $customUrl }}">
    @endif
    <style>
        :root { --ink:#0d2f6e; --accent:#1a56c8; }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; font-family: Tahoma, "Segoe UI", sans-serif;
            background: linear-gradient(160deg, #0a2858, #1a56c8 55%, #e8f0ff);
            color: var(--ink); display: flex; align-items: center; justify-content: center;
            padding: 24px;
        }
        .card {
            width: min(420px, 100%); background: #fff; border-radius: 20px;
            padding: 28px 24px; box-shadow: 0 18px 50px rgba(10,40,90,.25); text-align: center;
        }
        h1 { font-size: 1.2rem; margin: 0 0 8px; }
        p { color: #5a6d8c; margin: 0 0 16px; line-height: 1.65; font-size: .95rem; }
        .btn {
            display: block; width: 100%; margin: 10px 0; padding: 14px 16px; border-radius: 12px;
            text-decoration: none; font-weight: 700; border: 0; cursor: pointer;
        }
        .btn-primary { background: var(--accent); color: #fff; }
        .btn-secondary { background: #e8eef8; color: var(--ink); }
        .meta { margin-top: 14px; font-size: .72rem; color: #8a9bb5; word-break: break-all; direction: ltr; }
        .spinner { width: 28px; height: 28px; margin: 0 auto 14px; border: 3px solid #d6e2f5;
            border-top-color: var(--accent); border-radius: 50%; animation: spin .8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        #actions.ready .spinner { display: none; }
    </style>
</head>
<body>
<div class="card" id="card">
    <div class="spinner" id="spinner"></div>
    <h1>{{ $company }}</h1>
    <p id="status">جاري فتح التطبيق على الشاشة المناسبة…</p>

    <div id="actions">
        <a class="btn btn-primary" id="open-btn" href="{{ $isAndroid ? $intentUrl : $customUrl }}">افتح في التطبيق</a>
        @if($androidStore)
            <a class="btn btn-secondary" href="{{ $androidStore }}" @if(!$isAndroid) style="display:none" id="play-btn" @endif>تحميل Android</a>
        @endif
        @if($iosStore)
            <a class="btn btn-secondary" href="{{ $iosStore }}" @if(!$isIos) style="display:none" id="ios-btn" @endif>تحميل iOS</a>
        @endif
        @if(!$androidStore && !$iosStore)
            <p style="font-size:.85rem;color:#8a9bb5;margin-top:8px">ثبّت تطبيق MG ثم اضغط «افتح في التطبيق».</p>
        @endif
    </div>

    <div class="meta" dir="ltr">{{ $httpsUrl }}</div>
</div>

{{-- Hidden iframe helps iOS / some WebViews leave the page into the app --}}
<iframe id="app-frame" style="display:none;width:0;height:0;border:0" title="app"></iframe>

<script>
(function () {
    var isAndroid = @json($isAndroid);
    var isIos = @json($isIos);
    var isWhatsApp = @json($isWhatsApp);
    var customUrl = @json($customUrl);
    var intentUrl = @json($intentUrl);
    var storeAndroid = @json($androidStore);
    var storeIos = @json($iosStore);

    var openBtn = document.getElementById('open-btn');
    var actions = document.getElementById('actions');
    var statusEl = document.getElementById('status');
    var frame = document.getElementById('app-frame');
    var playBtn = document.getElementById('play-btn');
    var iosBtn = document.getElementById('ios-btn');

    // Prefer intent on Android (works better from Chrome/WhatsApp), custom scheme elsewhere.
    var target = isAndroid ? intentUrl : customUrl;
    openBtn.href = target;

    if (playBtn && (isAndroid || (!isAndroid && !isIos))) playBtn.style.display = 'block';
    if (iosBtn && (isIos || (!isAndroid && !isIos))) iosBtn.style.display = 'block';

    function reveal() {
        actions.classList.add('ready');
        var sp = document.getElementById('spinner');
        if (sp) sp.style.display = 'none';
        statusEl.textContent = 'إن لم يُفتح التطبيق تلقائياً، استخدم الزر بالأسفل.';
    }

    function tryOpen() {
        var start = Date.now();

        // 1) iframe (iOS / WhatsApp WebView)
        try { frame.src = customUrl; } catch (e) {}

        // 2) top-level navigation
        setTimeout(function () {
            try {
                window.location.href = target;
            } catch (e) {}
        }, isWhatsApp ? 250 : 50);

        // 3) If still visible after ~1.6s, show fallback UI
        setTimeout(function () {
            if (document.hidden) return;
            reveal();
        }, 1600);

        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                // App likely opened — keep quiet
            } else if (Date.now() - start > 800) {
                reveal();
            }
        });
    }

    // Auto-attempt once; user can retry via button
    tryOpen();
    openBtn.addEventListener('click', function (e) {
        // Let the <a href> work; also poke iframe for stubborn WebViews
        try { frame.src = customUrl; } catch (err) {}
    });
})();
</script>
</body>
</html>
