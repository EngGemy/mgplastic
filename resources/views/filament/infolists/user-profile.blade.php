@php
    use App\Models\User;
    use App\Support\UserRoles;
    use Illuminate\Support\Facades\Storage;

    $record = $getRecord();
    $role   = $record->role;
    $group  = UserRoles::group($role);

    $isPlumber   = $role === User::ROLE_PLUMBER;
    $isVendor    = $role === User::ROLE_VENDOR;
    $isAdmin     = in_array($role, ['super_admin', 'admin'], true);
    $isWholesale = $role === 'wholesale_distributor';
    $isRetail    = $role === 'retail_trader';
    $isNetwork   = $isWholesale || $isRetail;

    $roleLabel = UserRoles::label($role);

    $photoUrl = $record->profile_photo
        ? Storage::disk('public')->url($record->profile_photo)
        : null;

    // Accent palette keyed by role group.
    $accents = [
        'admin'   => ['#7c3aed', '#4f46e5'],
        'network' => ['#1a56db', '#0891b2'],
        'field'   => ['#059669', '#0d9488'],
        'app'     => ['#475569', '#64748b'],
    ];
    [$c1, $c2] = $accents[$group] ?? $accents['app'];

    $roleEmoji = [
        'super_admin'           => '🛡️',
        'admin'                 => '🔑',
        'wholesale_distributor' => '🏪',
        'retail_trader'         => '🏬',
        User::ROLE_PLUMBER      => '🔧',
        User::ROLE_VENDOR       => '🛍️',
        'user'                  => '👤',
    ][$role] ?? '👤';

    // Role-scoped counters.
    $works       = $isPlumber ? $record->workPhotos()->get() : collect();
    $worksTotal  = $works->count();
    $worksVideos = $works->filter(fn ($p) => $p->is_video)->count();
    $worksImages = $worksTotal - $worksVideos;

    $retailCount = $isWholesale ? $record->retailTraders()->count() : 0;
    $plumberCount = $isWholesale
        ? User::where('role', User::ROLE_PLUMBER)
            ->whereIn('parent_distributor_id', $record->retailTraders()->pluck('id'))->count()
        : ($isRetail ? $record->plumbers()->count() : 0);

    $permissions = is_array($record->permissions) ? $record->permissions : [];

    $countryName = app()->getLocale() === 'ar'
        ? ($record->country->name_ar ?? $record->country->name_en ?? null)
        : ($record->country->name_en ?? null);
    $cityName = app()->getLocale() === 'ar'
        ? ($record->city->name_ar ?? $record->city->name_en ?? null)
        : ($record->city->name_en ?? null);
@endphp

<div class="mgp" dir="rtl" style="--c1:{{ $c1 }};--c2:{{ $c2 }}">
    <style>
        .mgp{font-family:'Cairo',system-ui,sans-serif;color:#0f172a}
        .mgp *{box-sizing:border-box}
        .mgp-hero{position:relative;border-radius:26px;overflow:hidden;padding:30px 28px;color:#fff;
            background:linear-gradient(125deg,var(--c1),var(--c2));box-shadow:0 18px 46px -18px var(--c1)}
        .mgp-hero::after{content:"";position:absolute;inset-inline-end:-60px;top:-60px;width:240px;height:240px;
            border-radius:50%;background:rgba(255,255,255,.12)}
        .mgp-hero::before{content:"";position:absolute;inset-inline-start:-40px;bottom:-80px;width:200px;height:200px;
            border-radius:50%;background:rgba(255,255,255,.08)}
        .mgp-hero-in{position:relative;z-index:2;display:flex;align-items:center;gap:22px;flex-wrap:wrap}
        .mgp-avatar{width:110px;height:110px;border-radius:28px;object-fit:cover;flex-shrink:0;
            border:4px solid rgba(255,255,255,.55);box-shadow:0 12px 30px rgba(0,0,0,.28);background:#fff}
        .mgp-avatar.empty{display:flex;align-items:center;justify-content:center;font-size:44px;font-weight:800;
            color:var(--c1);background:#fff}
        .mgp-id{flex:1;min-width:220px}
        .mgp-role-chip{display:inline-flex;align-items:center;gap:7px;padding:6px 14px;border-radius:999px;
            background:rgba(255,255,255,.2);font-size:13px;font-weight:800;margin-bottom:8px;backdrop-filter:blur(4px)}
        .mgp-name{font-size:29px;font-weight:900;margin:0 0 6px;line-height:1.15}
        .mgp-sub{display:flex;gap:16px;flex-wrap:wrap;font-size:13.5px;opacity:.95;font-weight:600}
        .mgp-sub span{display:inline-flex;align-items:center;gap:6px}
        .mgp-pills{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
        .mgp-pill{display:inline-flex;align-items:center;gap:6px;padding:6px 13px;border-radius:999px;
            font-size:12px;font-weight:800;background:rgba(255,255,255,.16)}
        .mgp-pill.on{background:rgba(16,185,129,.9)}
        .mgp-pill.off{background:rgba(239,68,68,.9)}

        .mgp-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px;margin-top:18px}
        .mgp-stat{background:#fff;border:1px solid #eef2f7;border-radius:20px;padding:18px 16px;text-align:center;
            box-shadow:0 8px 22px -14px rgba(15,23,42,.4);transition:transform .18s ease}
        .mgp-stat:hover{transform:translateY(-3px)}
        .mgp-stat .ic{font-size:26px;margin-bottom:6px}
        .mgp-stat .num{font-size:26px;font-weight:900;color:var(--c1);line-height:1}
        .mgp-stat .lbl{font-size:12.5px;color:#64748b;font-weight:700;margin-top:5px}

        .mgp-grid{display:grid;grid-template-columns:2fr 1fr;gap:18px;margin-top:18px;align-items:start}
        @media(max-width:900px){.mgp-grid{grid-template-columns:1fr}}
        .mgp-card{background:#fff;border:1px solid #eef2f7;border-radius:22px;padding:22px 22px;
            box-shadow:0 10px 26px -18px rgba(15,23,42,.5)}
        .mgp-card h3{display:flex;align-items:center;gap:9px;font-size:16px;font-weight:900;margin:0 0 16px;
            padding-bottom:12px;border-bottom:2px solid #f1f5f9}
        .mgp-card h3 .dot{width:10px;height:10px;border-radius:50%;background:var(--c1)}
        .mgp-info{display:grid;grid-template-columns:1fr 1fr;gap:14px}
        @media(max-width:520px){.mgp-info{grid-template-columns:1fr}}
        .mgp-field{display:flex;gap:11px;align-items:flex-start}
        .mgp-field .fi{width:40px;height:40px;border-radius:12px;flex-shrink:0;display:flex;align-items:center;
            justify-content:center;font-size:19px;background:linear-gradient(135deg,rgba(0,0,0,.04),rgba(0,0,0,.02))}
        .mgp-field .ft{flex:1;min-width:0}
        .mgp-field .fl{font-size:11.5px;color:#94a3b8;font-weight:700;margin-bottom:2px}
        .mgp-field .fv{font-size:14.5px;color:#0f172a;font-weight:700;word-break:break-word}
        .mgp-field .fv.muted{color:#cbd5e1;font-weight:600}

        .mgp-about{font-size:14px;line-height:1.9;color:#334155;white-space:pre-line}
        .mgp-about-block{margin-bottom:14px}
        .mgp-about-block:last-child{margin-bottom:0}
        .mgp-about-block .k{font-size:12px;color:#94a3b8;font-weight:800;margin-bottom:4px}

        .mgp-perms{display:flex;flex-wrap:wrap;gap:8px}
        .mgp-perm{padding:7px 13px;border-radius:999px;font-size:12.5px;font-weight:700;
            background:rgba(124,58,237,.08);color:#6d28d9;border:1px solid rgba(124,58,237,.18)}
        .mgp-perm.full{background:rgba(16,185,129,.1);color:#047857;border-color:rgba(16,185,129,.25)}

        .mgp-video{margin-top:14px;border-radius:16px;overflow:hidden;background:#000;aspect-ratio:16/9}
        .mgp-video iframe,.mgp-video video{width:100%;height:100%;border:0;display:block}

        .mgp-section-title{display:flex;align-items:center;gap:10px;font-size:18px;font-weight:900;margin:26px 0 14px}
        .mgp-section-title .bar{width:5px;height:22px;border-radius:4px;background:linear-gradient(var(--c1),var(--c2))}
    </style>

    {{-- HERO --}}
    <div class="mgp-hero">
        <div class="mgp-hero-in">
            @if($photoUrl)
                <img src="{{ $photoUrl }}" alt="" class="mgp-avatar">
            @else
                <div class="mgp-avatar empty">{{ mb_strtoupper(mb_substr($record->name ?? '؟', 0, 1)) }}</div>
            @endif

            <div class="mgp-id">
                <span class="mgp-role-chip">{{ $roleEmoji }} {{ $roleLabel }}</span>
                <h2 class="mgp-name">{{ $record->name }}</h2>
                <div class="mgp-sub">
                    @if($record->phone)<span>📞 {{ $record->phone }}</span>@endif
                    @if($cityName || $countryName)<span>📍 {{ collect([$cityName, $countryName])->filter()->implode('، ') }}</span>@endif
                    @if($record->created_at)<span>🗓️ انضم {{ $record->created_at->format('Y/m/d') }}</span>@endif
                </div>

                <div class="mgp-pills">
                    <span class="mgp-pill {{ $record->is_active ? 'on' : 'off' }}">
                        {{ $record->is_active ? '✓ حساب نشط' : '✕ موقوف' }}
                    </span>
                    <span class="mgp-pill {{ $record->is_approved ? 'on' : 'off' }}">
                        {{ $record->is_approved ? '✓ معتمد' : '⏳ بانتظار الاعتماد' }}
                    </span>
                    <span class="mgp-pill {{ $record->is_phone_verified ? 'on' : 'off' }}">
                        {{ $record->is_phone_verified ? '✓ هاتف موثّق' : '✕ هاتف غير موثّق' }}
                    </span>
                </div>
            </div>
        </div>
    </div>

    {{-- STATS --}}
    <div class="mgp-stats">
        @if($isPlumber)
            <div class="mgp-stat"><div class="ic">🖼️</div><div class="num">{{ number_format($worksTotal) }}</div><div class="lbl">إجمالي الأعمال</div></div>
            <div class="mgp-stat"><div class="ic">📷</div><div class="num">{{ number_format($worksImages) }}</div><div class="lbl">صورة</div></div>
            <div class="mgp-stat"><div class="ic">🎬</div><div class="num">{{ number_format($worksVideos) }}</div><div class="lbl">فيديو</div></div>
        @elseif($isWholesale)
            <div class="mgp-stat"><div class="ic">🏬</div><div class="num">{{ number_format($retailCount) }}</div><div class="lbl">تاجر قطاعي</div></div>
            <div class="mgp-stat"><div class="ic">🔧</div><div class="num">{{ number_format($plumberCount) }}</div><div class="lbl">سباك بالشبكة</div></div>
        @elseif($isRetail)
            <div class="mgp-stat"><div class="ic">🔧</div><div class="num">{{ number_format($plumberCount) }}</div><div class="lbl">سباك تابع</div></div>
        @elseif($isAdmin)
            <div class="mgp-stat"><div class="ic">🔑</div><div class="num">{{ $role === 'super_admin' ? '∞' : count($permissions) }}</div><div class="lbl">{{ $role === 'super_admin' ? 'صلاحيات كاملة' : 'صلاحية' }}</div></div>
        @endif
        <div class="mgp-stat"><div class="ic">🆔</div><div class="num">#{{ $record->id }}</div><div class="lbl">رقم الحساب</div></div>
    </div>

    {{-- MAIN GRID --}}
    <div class="mgp-grid">
        <div>
            {{-- Contact & location --}}
            <div class="mgp-card">
                <h3><span class="dot"></span> معلومات التواصل والموقع</h3>
                <div class="mgp-info">
                    <div class="mgp-field">
                        <div class="fi">📞</div>
                        <div class="ft"><div class="fl">رقم الهاتف</div><div class="fv {{ $record->phone ? '' : 'muted' }}">{{ $record->phone ?: '—' }}</div></div>
                    </div>
                    <div class="mgp-field">
                        <div class="fi">✉️</div>
                        <div class="ft"><div class="fl">البريد الإلكتروني</div><div class="fv {{ $record->email ? '' : 'muted' }}">{{ $record->email ?: '—' }}</div></div>
                    </div>
                    <div class="mgp-field">
                        <div class="fi">🌍</div>
                        <div class="ft"><div class="fl">الدولة</div><div class="fv {{ $countryName ? '' : 'muted' }}">{{ $countryName ?: '—' }}</div></div>
                    </div>
                    <div class="mgp-field">
                        <div class="fi">🏙️</div>
                        <div class="ft"><div class="fl">المدينة</div><div class="fv {{ $cityName ? '' : 'muted' }}">{{ $cityName ?: '—' }}</div></div>
                    </div>
                    @if($record->address)
                    <div class="mgp-field">
                        <div class="fi">🧭</div>
                        <div class="ft"><div class="fl">العنوان</div><div class="fv">{{ $record->address }}</div></div>
                    </div>
                    @endif
                    @if($record->parentDistributor)
                    <div class="mgp-field">
                        <div class="fi">🔗</div>
                        <div class="ft"><div class="fl">المسؤول المباشر</div><div class="fv">{{ $record->parentDistributor->name }}</div></div>
                    </div>
                    @endif
                    @if($record->brand_name)
                    <div class="mgp-field">
                        <div class="fi">🏷️</div>
                        <div class="ft"><div class="fl">اسم المتجر / العلامة</div><div class="fv">{{ $record->brand_name }}</div></div>
                    </div>
                    @endif
                    @if($record->website)
                    <div class="mgp-field">
                        <div class="fi">🌐</div>
                        <div class="ft"><div class="fl">الموقع</div><div class="fv"><a href="{{ $record->website }}" target="_blank" rel="noopener" style="color:var(--c1)">{{ $record->website }}</a></div></div>
                    </div>
                    @endif
                </div>
            </div>

            @php
                $socialLinks = ($isPlumber || $isNetwork)
                    ? $record->socialLinks()->orderBy('sort_order')->get()
                    : collect();
            @endphp
            @if($socialLinks->isNotEmpty())
            <div class="mgp-card" style="margin-top:18px">
                <h3><span class="dot"></span> روابط التواصل الاجتماعي</h3>
                <div class="mgp-info">
                    @foreach($socialLinks as $link)
                    <div class="mgp-field">
                        <div class="fi">🔗</div>
                        <div class="ft">
                            <div class="fl">{{ \App\Models\SocialLink::PLATFORMS[$link->platform] ?? $link->platform }}</div>
                            <div class="fv"><a href="{{ $link->url }}" target="_blank" rel="noopener" style="color:var(--c1);word-break:break-all">{{ $link->url }}</a></div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- About / skills --}}
            @if($record->about_me || $record->short_description || $record->long_description || $record->store_description)
            <div class="mgp-card" style="margin-top:18px">
                <h3><span class="dot"></span> {{ $isPlumber ? 'نبذة ومهارات السبّاك' : 'نبذة تعريفية' }}</h3>
                @if($record->short_description)
                    <div class="mgp-about-block"><div class="k">وصف مختصر</div><div class="mgp-about">{{ $record->short_description }}</div></div>
                @endif
                @if($record->about_me)
                    <div class="mgp-about-block"><div class="k">نبذة</div><div class="mgp-about">{{ $record->about_me }}</div></div>
                @endif
                @if($record->long_description)
                    <div class="mgp-about-block"><div class="k">تفاصيل</div><div class="mgp-about">{{ $record->long_description }}</div></div>
                @endif
                @if($record->store_description)
                    <div class="mgp-about-block"><div class="k">وصف المتجر</div><div class="mgp-about">{{ $record->store_description }}</div></div>
                @endif
            </div>
            @endif
        </div>

        {{-- Side column --}}
        <div>
            <div class="mgp-card">
                <h3><span class="dot"></span> بيانات الحساب</h3>
                <div class="mgp-field" style="margin-bottom:14px">
                    <div class="fi">{{ $roleEmoji }}</div>
                    <div class="ft"><div class="fl">الدور الوظيفي</div><div class="fv">{{ $roleLabel }}</div></div>
                </div>
                <div class="mgp-field" style="margin-bottom:14px">
                    <div class="fi">🗓️</div>
                    <div class="ft"><div class="fl">تاريخ الانضمام</div><div class="fv">{{ optional($record->created_at)->format('Y/m/d') ?: '—' }}</div></div>
                </div>
                <div class="mgp-field">
                    <div class="fi">🔄</div>
                    <div class="ft"><div class="fl">آخر تحديث</div><div class="fv">{{ optional($record->updated_at)->diffForHumans() ?: '—' }}</div></div>
                </div>
            </div>

            @if($isAdmin)
            <div class="mgp-card" style="margin-top:18px">
                <h3><span class="dot"></span> صلاحيات لوحة التحكم</h3>
                <div class="mgp-perms">
                    @if($role === 'super_admin' || empty($permissions))
                        <span class="mgp-perm full">✓ صلاحيات كاملة على النظام</span>
                    @else
                        @foreach($permissions as $perm)
                            <span class="mgp-perm">{{ \App\Support\AdminPermissions::labels()[$perm] ?? $perm }}</span>
                        @endforeach
                    @endif
                </div>
            </div>
            @endif

            @if($record->video_url)
            <div class="mgp-card" style="margin-top:18px">
                <h3><span class="dot"></span> فيديو تعريفي</h3>
                <div class="mgp-video">
                    <video src="{{ $record->video_url }}" controls playsinline></video>
                </div>
            </div>
            @endif
        </div>
    </div>

    {{-- Plumber works gallery --}}
    @if($isPlumber)
        <div class="mgp-section-title"><span class="bar"></span> 🔧 معرض أعمال السبّاك</div>
        @include('filament.forms.plumber-work-gallery', ['galleryRecord' => $record])
    @endif
</div>
