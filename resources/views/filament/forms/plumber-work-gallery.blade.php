@php
    /** @var \Filament\Forms\Components\ViewField $field */
    $record = $field->getRecord();
    $items = $record
        ? $record->workPhotos()->latest()->get()
        : collect();

    $videos = $items->filter(fn ($p) => $p->is_video);
    $images = $items->reject(fn ($p) => $p->is_video);
@endphp

<div class="mg-works" dir="rtl">
    <style>
        .mg-works{font-family:'Cairo',sans-serif}
        .mg-works-stats{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px}
        .mg-chip{display:flex;align-items:center;gap:8px;padding:10px 16px;border-radius:14px;
            background:linear-gradient(135deg,rgba(26,86,219,.08),rgba(8,145,178,.06));
            border:1px solid rgba(26,86,219,.15);font-size:13px;font-weight:700;color:#0d1b2a}
        .mg-chip .n{font-size:20px;font-weight:800;color:#1a56db;line-height:1}
        .mg-chip.vid .n{color:#7c3aed}
        .mg-works-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px}
        .mg-tile{position:relative;aspect-ratio:1;border-radius:16px;overflow:hidden;cursor:pointer;
            border:1px solid #e5e7eb;background:#0f172a;box-shadow:0 4px 14px rgba(15,23,42,.06);
            transition:transform .2s ease,box-shadow .2s ease}
        .mg-tile:hover{transform:translateY(-4px);box-shadow:0 14px 32px rgba(15,23,42,.18)}
        .mg-tile img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .35s ease}
        .mg-tile:hover img{transform:scale(1.07)}
        .mg-tile .badge{position:absolute;top:8px;inset-inline-start:8px;z-index:3;
            display:inline-flex;align-items:center;gap:4px;padding:4px 9px;border-radius:999px;
            font-size:10px;font-weight:800;color:#fff;backdrop-filter:blur(4px)}
        .mg-tile .badge.vid{background:rgba(124,58,237,.85)}
        .mg-tile .badge.img{background:rgba(26,86,219,.82)}
        .mg-play{position:absolute;inset:0;z-index:2;display:flex;align-items:center;justify-content:center;
            background:linear-gradient(180deg,rgba(0,0,0,.05),rgba(0,0,0,.45))}
        .mg-play span{width:52px;height:52px;border-radius:50%;background:rgba(255,255,255,.92);
            display:flex;align-items:center;justify-content:center;color:#7c3aed;font-size:22px;
            box-shadow:0 6px 18px rgba(0,0,0,.35);transition:transform .2s ease}
        .mg-tile:hover .mg-play span{transform:scale(1.12)}
        .mg-tile .date{position:absolute;bottom:0;inset-inline:0;z-index:3;padding:14px 8px 6px;
            font-size:10px;color:#fff;background:linear-gradient(0deg,rgba(0,0,0,.6),transparent);
            text-align:center;letter-spacing:.02em}
        .mg-works-empty{padding:36px 20px;text-align:center;border:1.5px dashed #cbd5e1;border-radius:16px;
            color:#64748b;font-size:14px;background:#f8fafc}
        .mg-works-empty .ic{font-size:34px;margin-bottom:8px;color:#94a3b8}
        .mg-lightbox{position:fixed;inset:0;z-index:9999;display:none;align-items:center;justify-content:center;
            background:rgba(2,6,23,.88);backdrop-filter:blur(6px);padding:24px}
        .mg-lightbox.open{display:flex}
        .mg-lightbox .box{max-width:min(920px,95vw);max-height:90vh;width:auto}
        .mg-lightbox img,.mg-lightbox video{max-width:100%;max-height:90vh;border-radius:14px;
            box-shadow:0 20px 60px rgba(0,0,0,.5);background:#000;display:block}
        .mg-lightbox .x{position:absolute;top:18px;inset-inline-end:22px;width:44px;height:44px;border:none;
            border-radius:50%;background:rgba(255,255,255,.14);color:#fff;font-size:22px;cursor:pointer;
            display:flex;align-items:center;justify-content:center}
        .mg-lightbox .x:hover{background:rgba(255,255,255,.28)}
    </style>

    @if($items->isEmpty())
        <div class="mg-works-empty">
            <div class="ic">📷</div>
            <div>لا توجد أعمال بعد. سيظهر هنا ما يرفعه السباك من صور وفيديوهات عبر التطبيق تلقائياً.</div>
        </div>
    @else
        <div class="mg-works-stats">
            <div class="mg-chip"><span class="n">{{ $items->count() }}</span> إجمالي الأعمال</div>
            <div class="mg-chip"><span class="n">{{ $images->count() }}</span> صورة</div>
            <div class="mg-chip vid"><span class="n">{{ $videos->count() }}</span> فيديو</div>
        </div>

        <div class="mg-works-grid" wire:ignore>
            @foreach($items as $p)
                <div class="mg-tile"
                     data-mg-media
                     data-mg-type="{{ $p->is_video ? 'video' : 'image' }}"
                     data-mg-url="{{ $p->url }}">
                    @if($p->is_video)
                        <span class="badge vid">● فيديو</span>
                        @if($p->thumbnail_url)
                            <img src="{{ $p->thumbnail_url }}" alt="عمل السباك" loading="lazy">
                        @endif
                        <div class="mg-play"><span>▶</span></div>
                    @else
                        <span class="badge img">صورة</span>
                        <img src="{{ $p->thumbnail_url ?? $p->url }}" alt="عمل السباك" loading="lazy">
                    @endif
                    <div class="date">{{ optional($p->created_at)->format('Y/m/d') }}</div>
                </div>
            @endforeach
        </div>
    @endif

    @script
    <script>
        if (! window.__mgWorksInit) {
            window.__mgWorksInit = true;

            const ensureBox = () => {
                let box = document.getElementById('mg-lightbox');
                if (box) return box;
                box = document.createElement('div');
                box.id = 'mg-lightbox';
                box.className = 'mg-lightbox';
                box.innerHTML = '<button type="button" class="x" aria-label="إغلاق">✕</button><div class="box"></div>';
                document.body.appendChild(box);
                box.addEventListener('click', (e) => {
                    if (e.target === box || e.target.classList.contains('x')) closeBox();
                });
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape') closeBox();
                });
                return box;
            };

            const closeBox = () => {
                const box = document.getElementById('mg-lightbox');
                if (! box) return;
                box.classList.remove('open');
                box.querySelector('.box').innerHTML = '';
                document.body.style.overflow = '';
            };

            const openBox = (url, type) => {
                if (! url) return;
                const box = ensureBox();
                box.querySelector('.box').innerHTML = type === 'video'
                    ? '<video src="' + url + '" controls autoplay playsinline></video>'
                    : '<img src="' + url + '" alt="">';
                box.classList.add('open');
                document.body.style.overflow = 'hidden';
            };

            document.addEventListener('click', (e) => {
                const tile = e.target.closest('[data-mg-media]');
                if (! tile) return;
                openBox(tile.getAttribute('data-mg-url'), tile.getAttribute('data-mg-type'));
            });
        }
    </script>
    @endscript
</div>
