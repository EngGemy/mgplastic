@php
    $mediaType = $mediaType ?? 'image';
    $source = $source ?? 'link';
    $videoUrl = $videoUrl ?? null;
    $videoPath = is_array($videoPath ?? null) ? (($videoPath[0] ?? null)) : ($videoPath ?? null);
    $coverPath = is_array($coverPath ?? null) ? (($coverPath[0] ?? null)) : ($coverPath ?? null);
    $title = trim((string) ($title ?? 'معاينة الإعلان'));

    $coverUrl = null;
    if (filled($coverPath)) {
        $coverUrl = \App\Support\PublicStorageUrl::try($coverPath);
        if (! $coverUrl && \Illuminate\Support\Facades\Storage::disk('public')->exists($coverPath)) {
            $coverUrl = \Illuminate\Support\Facades\Storage::disk('public')->url($coverPath);
        }
    }

    $embed = \App\Support\VideoEmbed::resolve(
        $source === 'link' ? $videoUrl : null,
        $source === 'upload' ? $videoPath : null,
    );
@endphp

<div class="mg-promo-studio" dir="rtl">
    <div class="mg-promo-studio__chrome">
        <div class="mg-promo-studio__dots" aria-hidden="true">
            <span></span><span></span><span></span>
        </div>
        <div class="mg-promo-studio__meta">
            <span class="mg-promo-studio__badge">{{ $embed['label'] }}</span>
            <span class="mg-promo-studio__title">{{ \Illuminate\Support\Str::limit($title, 42) }}</span>
        </div>
        <div class="mg-promo-studio__live"><i></i> LIVE</div>
    </div>

    <div class="mg-promo-studio__stage">
        @if ($mediaType !== 'video')
            <div class="mg-promo-studio__empty">
                <strong>استوديو الفيديو</strong>
                <p>اختَر نوع المحتوى «فيديو» لمعاينة التشغيل هنا.</p>
            </div>
        @elseif ($embed['kind'] === 'empty')
            <div class="mg-promo-studio__empty">
                @if ($coverUrl)
                    <img src="{{ $coverUrl }}" alt="" class="mg-promo-studio__cover-ghost">
                @endif
                <strong>جاهز للمعاينة</strong>
                <p>ارفع ملفًا على السيرفر أو الصق رابط يوتيوب / فيميو / Drive.</p>
            </div>
        @elseif ($embed['can_iframe'] && $embed['embed_url'])
            <iframe
                class="mg-promo-studio__frame"
                src="{{ $embed['embed_url'] }}"
                title="معاينة فيديو الإعلان"
                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                allowfullscreen
                loading="lazy"
                referrerpolicy="strict-origin-when-cross-origin"
            ></iframe>
        @elseif ($embed['can_html5'] && $embed['playback_url'])
            <video
                class="mg-promo-studio__video"
                controls
                playsinline
                preload="metadata"
                @if($coverUrl) poster="{{ $coverUrl }}" @endif
                src="{{ $embed['playback_url'] }}"
            >متصفحك لا يدعم تشغيل الفيديو.</video>
        @else
            <div class="mg-promo-studio__empty">
                @if ($coverUrl)
                    <img src="{{ $coverUrl }}" alt="" class="mg-promo-studio__cover-ghost">
                @endif
                <strong>الرابط محفوظ — المعاينة غير مباشرة</strong>
                <p>بعض الروابط تحتاج فتحها خارج اللوحة للتحقق.</p>
                @if ($embed['playback_url'])
                    <a class="mg-promo-studio__open" href="{{ $embed['playback_url'] }}" target="_blank" rel="noopener">فتح الرابط ↗</a>
                @endif
            </div>
        @endif
    </div>

    <div class="mg-promo-studio__footer">
        <span>المصدر: <b>{{ $source === 'upload' ? 'رفع سيرفر' : 'رابط خارجي' }}</b></span>
        @if ($embed['playback_url'])
            <a href="{{ $embed['playback_url'] }}" target="_blank" rel="noopener">فتح المصدر</a>
        @endif
    </div>
</div>

<style>
@import url('https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;600;700&family=Syne:wght@700;800&display=swap');
.mg-promo-studio{--mg-ember:#d6452b;font-family:"IBM Plex Sans Arabic",sans-serif;border:1px solid rgba(20,17,15,.12);border-radius:18px;overflow:hidden;background:radial-gradient(120% 80% at 100% 0%,rgba(214,69,43,.16),transparent 55%),linear-gradient(165deg,#1b1815 0%,#0f0d0c 55%,#171411 100%);color:#f3eee6;box-shadow:0 18px 40px rgba(15,13,12,.28)}
.mg-promo-studio__chrome{display:grid;grid-template-columns:auto 1fr auto;gap:.75rem;align-items:center;padding:.7rem .9rem;border-bottom:1px solid rgba(243,238,230,.08);background:rgba(0,0,0,.28)}
.mg-promo-studio__dots{display:flex;gap:.35rem}
.mg-promo-studio__dots span{width:.55rem;height:.55rem;border-radius:999px;background:#5c564f}
.mg-promo-studio__dots span:nth-child(1){background:#d6452b}
.mg-promo-studio__dots span:nth-child(2){background:#d4a017}
.mg-promo-studio__dots span:nth-child(3){background:#3f8f5a}
.mg-promo-studio__meta{min-width:0;display:flex;flex-direction:column;gap:.1rem}
.mg-promo-studio__badge{font-family:Syne,sans-serif;font-size:.65rem;letter-spacing:.08em;text-transform:uppercase;color:#ffb4a4;font-weight:700}
.mg-promo-studio__title{font-size:.82rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mg-promo-studio__live{display:inline-flex;align-items:center;gap:.35rem;font-family:Syne,sans-serif;font-size:.65rem;font-weight:800;letter-spacing:.12em;color:#ffd7cf;background:rgba(214,69,43,.22);border:1px solid rgba(214,69,43,.45);border-radius:999px;padding:.28rem .55rem}
.mg-promo-studio__live i{width:.45rem;height:.45rem;border-radius:999px;background:#ff5a3d;animation:mgPulse 1.6s infinite}
@keyframes mgPulse{0%{box-shadow:0 0 0 0 rgba(255,90,61,.55)}70%{box-shadow:0 0 0 8px rgba(255,90,61,0)}100%{box-shadow:0 0 0 0 rgba(255,90,61,0)}}
.mg-promo-studio__stage{position:relative;aspect-ratio:16/9;background:linear-gradient(rgba(243,238,230,.04) 1px,transparent 1px) 0 0/24px 24px,linear-gradient(90deg,rgba(243,238,230,.04) 1px,transparent 1px) 0 0/24px 24px,#090807}
.mg-promo-studio__frame,.mg-promo-studio__video{width:100%;height:100%;border:0;display:block;background:#000}
.mg-promo-studio__empty{position:absolute;inset:0;display:grid;place-content:center;gap:.35rem;text-align:center;padding:1.25rem;color:rgba(243,238,230,.88)}
.mg-promo-studio__empty strong{font-family:Syne,"IBM Plex Sans Arabic",sans-serif;font-size:1rem}
.mg-promo-studio__empty p{margin:0;font-size:.82rem;color:rgba(243,238,230,.62);max-width:28rem}
.mg-promo-studio__cover-ghost{width:min(48%,220px);aspect-ratio:16/9;object-fit:cover;border-radius:10px;opacity:.35;margin:0 auto .55rem}
.mg-promo-studio__open{display:inline-flex;margin-top:.55rem;justify-self:center;color:#ffd7cf;font-weight:600;font-size:.82rem;text-decoration:underline;text-underline-offset:3px}
.mg-promo-studio__footer{display:flex;justify-content:space-between;gap:.75rem;align-items:center;padding:.65rem .9rem;border-top:1px solid rgba(243,238,230,.08);font-size:.75rem;color:rgba(243,238,230,.7);background:rgba(0,0,0,.22)}
.mg-promo-studio__footer a{color:#ffb4a4;font-weight:600;text-decoration:none}
.mg-promo-studio__footer a:hover{text-decoration:underline}
.mg-promo-studio__footer b{color:#fff}
</style>
