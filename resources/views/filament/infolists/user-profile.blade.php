@php
    use App\Models\User;
    use App\Support\UserRoles;
    use Illuminate\Support\Facades\Storage;

    $record = $getRecord();
    $role = $record->role;
    $group = UserRoles::group($role);

    $isPlumber = $role === User::ROLE_PLUMBER;
    $isAdmin = in_array($role, ['super_admin', 'admin'], true);
    $isWholesale = $role === 'wholesale_distributor';
    $isRetail = $role === 'retail_trader';
    $isNetwork = $isWholesale || $isRetail;

    $roleLabel = UserRoles::label($role);
    $photoUrl = $record->profile_photo
        ? Storage::disk('public')->url($record->profile_photo)
        : null;

    $works = $isPlumber ? $record->workPhotos()->get() : collect();
    $worksTotal = $works->count();

    $financeService = app(\App\Services\PlumberFinanceSummaryService::class);
    $finance = $isPlumber ? $financeService->for($record) : null;

    $linkedRetailIds = $isWholesale
        ? app(\App\Services\RetailNetworkLinkService::class)->linkedRetailerIds($record)
        : [];
    $retailCount = $isWholesale ? count($linkedRetailIds) : 0;
    $plumberCount = $isWholesale
        ? User::where('role', User::ROLE_PLUMBER)->whereIn('parent_distributor_id', $linkedRetailIds)->count()
        : ($isRetail ? $record->plumbers()->count() : 0);

    $points = $isWholesale
        ? app(\App\Services\WholesalerNetworkService::class)->getSummary($record)
        : ($isRetail ? app(\App\Services\RetailTraderNetworkService::class)->getSummary($record) : null);

    $permissions = is_array($record->permissions) ? $record->permissions : [];

    $countryName = app()->getLocale() === 'ar'
        ? ($record->country->name_ar ?? $record->country->name_en ?? null)
        : ($record->country->name_en ?? null);
    $cityName = app()->getLocale() === 'ar'
        ? ($record->city->name_ar ?? $record->city->name_en ?? null)
        : ($record->city->name_en ?? null);

    $location = collect([$cityName, $countryName])->filter()->implode('، ');
    $socialLinks = ($isPlumber || $isNetwork)
        ? $record->socialLinks()->orderBy('sort_order')->get()
        : collect();

    $tone = match ($group) {
        'admin' => 'violet',
        'network' => 'blue',
        'field' => 'green',
        default => 'slate',
    };
@endphp

<div class="mg-user mg-user--{{ $tone }}" dir="rtl">
    <header class="mg-user-hero">
        <div class="mg-user-identity">
            @if($photoUrl)
                <img src="{{ $photoUrl }}" alt="" class="mg-user-avatar">
            @else
                <div class="mg-user-avatar mg-user-avatar--empty">{{ mb_strtoupper(mb_substr($record->name ?? '؟', 0, 1)) }}</div>
            @endif
            <div class="mg-user-who">
                <div class="mg-user-role">{{ $roleLabel }} · #{{ $record->id }}</div>
                <h2 class="mg-user-name">{{ $record->name }}</h2>
                @if(filled($record->brand_name) && $record->brand_name !== $record->name)
                    <div class="mg-user-brand">{{ $record->brand_name }}</div>
                @endif
                <div class="mg-user-meta">
                    @if($record->phone)<span>{{ $record->phone }}</span>@endif
                    @if($location)<span>{{ $location }}</span>@endif
                    @if($record->created_at)<span>انضم {{ $record->created_at->format('Y/m/d') }}</span>@endif
                </div>
                @if(filled($record->network_code))
                    <div class="mg-user-code">
                        <x-copyable-network-code :code="$record->network_code" variant="chip" />
                    </div>
                @endif
            </div>
        </div>
        <div class="mg-user-flags">
            <span class="mg-user-flag {{ $record->is_active ? 'is-on' : 'is-off' }}">{{ $record->is_active ? 'نشط' : 'موقوف' }}</span>
            <span class="mg-user-flag {{ $record->is_approved ? 'is-on' : 'is-off' }}">{{ $record->is_approved ? 'معتمد' : 'بانتظار الاعتماد' }}</span>
            <span class="mg-user-flag {{ $record->is_phone_verified ? 'is-on' : 'is-off' }}">{{ $record->is_phone_verified ? 'هاتف موثّق' : 'هاتف غير موثّق' }}</span>
        </div>
    </header>

    @if($isPlumber || $isWholesale || $isRetail || $isAdmin)
        <section class="mg-user-kpis" aria-label="مؤشرات سريعة">
            @if($isPlumber && $finance)
                <article class="mg-user-kpi mg-user-kpi--amber">
                    <div class="mg-user-kpi-val">{{ number_format($finance['balance_points']) }}</div>
                    <div class="mg-user-kpi-lbl">رصيد النقاط</div>
                </article>
                <article class="mg-user-kpi mg-user-kpi--green">
                    <div class="mg-user-kpi-val">{{ $finance['balance_money_formatted'] }}</div>
                    <div class="mg-user-kpi-lbl">رصيد قابل للسحب</div>
                </article>
                <article class="mg-user-kpi mg-user-kpi--blue">
                    <div class="mg-user-kpi-val">{{ $finance['withdrawn_paid_formatted'] }}</div>
                    <div class="mg-user-kpi-lbl">تم صرفه</div>
                </article>
                <article class="mg-user-kpi mg-user-kpi--orange">
                    <div class="mg-user-kpi-val">{{ $finance['withdrawn_pending_formatted'] }}</div>
                    <div class="mg-user-kpi-lbl">سحب معلّق</div>
                </article>
                <article class="mg-user-kpi">
                    <div class="mg-user-kpi-val">{{ number_format($worksTotal) }}</div>
                    <div class="mg-user-kpi-lbl">أعمال المعرض</div>
                </article>
            @elseif($isWholesale)
                <article class="mg-user-kpi mg-user-kpi--amber">
                    <div class="mg-user-kpi-val">{{ number_format((int) ($points['factory_points'] ?? 0)) }}</div>
                    <div class="mg-user-kpi-lbl">نقاط المصنع</div>
                </article>
                <article class="mg-user-kpi mg-user-kpi--blue">
                    <div class="mg-user-kpi-val">{{ number_format((int) ($points['distributed_points'] ?? 0)) }}</div>
                    <div class="mg-user-kpi-lbl">موزّع للقطاعي</div>
                </article>
                <article class="mg-user-kpi mg-user-kpi--green">
                    <div class="mg-user-kpi-val">{{ number_format((int) ($points['remaining_points'] ?? 0)) }}</div>
                    <div class="mg-user-kpi-lbl">متبقي للتوزيع</div>
                </article>
                <article class="mg-user-kpi">
                    <div class="mg-user-kpi-val">{{ number_format($retailCount) }}</div>
                    <div class="mg-user-kpi-lbl">تاجر قطاعي</div>
                </article>
                <article class="mg-user-kpi">
                    <div class="mg-user-kpi-val">{{ number_format($plumberCount) }}</div>
                    <div class="mg-user-kpi-lbl">سباك بالشبكة</div>
                </article>
            @elseif($isRetail)
                <article class="mg-user-kpi mg-user-kpi--amber">
                    <div class="mg-user-kpi-val">{{ number_format((int) ($points['received_points'] ?? 0)) }}</div>
                    <div class="mg-user-kpi-lbl">نقاط مستلمة</div>
                </article>
                <article class="mg-user-kpi mg-user-kpi--blue">
                    <div class="mg-user-kpi-val">{{ number_format((int) ($points['distributed_points'] ?? 0)) }}</div>
                    <div class="mg-user-kpi-lbl">موزّع للسباكين</div>
                </article>
                <article class="mg-user-kpi mg-user-kpi--green">
                    <div class="mg-user-kpi-val">{{ number_format((int) ($points['remaining_points'] ?? 0)) }}</div>
                    <div class="mg-user-kpi-lbl">متبقي للبيع</div>
                </article>
                <article class="mg-user-kpi">
                    <div class="mg-user-kpi-val">{{ number_format($plumberCount) }}</div>
                    <div class="mg-user-kpi-lbl">سباك تابع</div>
                </article>
            @elseif($isAdmin)
                <article class="mg-user-kpi mg-user-kpi--violet">
                    <div class="mg-user-kpi-val">{{ $role === 'super_admin' ? 'كامل' : count($permissions) }}</div>
                    <div class="mg-user-kpi-lbl">{{ $role === 'super_admin' ? 'صلاحيات النظام' : 'صلاحية مفعّلة' }}</div>
                </article>
            @endif
        </section>
    @endif

    <div class="mg-user-grid">
        <section class="mg-user-card">
            <h3>التواصل والموقع</h3>
            <dl class="mg-user-dl">
                <div><dt>الهاتف</dt><dd>{{ $record->phone ?: '—' }}</dd></div>
                <div><dt>البريد</dt><dd>{{ $record->email ?: '—' }}</dd></div>
                <div><dt>الدولة</dt><dd>{{ $countryName ?: '—' }}</dd></div>
                <div><dt>المدينة</dt><dd>{{ $cityName ?: '—' }}</dd></div>
                @if($record->address)
                    <div class="mg-user-dl-full"><dt>العنوان</dt><dd>{{ $record->address }}</dd></div>
                @endif
                @if($record->parentDistributor)
                    <div class="mg-user-dl-full"><dt>المسؤول المباشر</dt><dd>{{ $record->parentDistributor->name }}</dd></div>
                @endif
                @if($record->website)
                    <div class="mg-user-dl-full"><dt>الموقع</dt><dd><a href="{{ $record->website }}" target="_blank" rel="noopener">{{ $record->website }}</a></dd></div>
                @endif
            </dl>
        </section>

        <section class="mg-user-card">
            <h3>بيانات الحساب</h3>
            <dl class="mg-user-dl">
                <div><dt>الدور</dt><dd>{{ $roleLabel }}</dd></div>
                <div><dt>الانضمام</dt><dd>{{ optional($record->created_at)->format('Y/m/d') ?: '—' }}</dd></div>
                <div><dt>آخر تحديث</dt><dd>{{ optional($record->updated_at)->diffForHumans() ?: '—' }}</dd></div>
                @if($isRetail && $record->is_independent)
                    <div><dt>النوع</dt><dd>قطاعي مستقل</dd></div>
                @endif
            </dl>

            @if($isAdmin)
                <div class="mg-user-perms">
                    <div class="mg-user-perms-title">صلاحيات اللوحة</div>
                    <div class="mg-user-perm-list">
                        @if($role === 'super_admin' || empty($permissions))
                            <span class="mg-user-perm mg-user-perm--full">صلاحيات كاملة</span>
                        @else
                            @foreach($permissions as $perm)
                                <span class="mg-user-perm">{{ \App\Support\AdminPermissions::labels()[$perm] ?? $perm }}</span>
                            @endforeach
                        @endif
                    </div>
                </div>
            @endif
        </section>
    </div>

    @if($socialLinks->isNotEmpty() || $record->about_me || $record->short_description || $record->long_description || $record->store_description)
        <div class="mg-user-grid">
            @if($socialLinks->isNotEmpty())
                <section class="mg-user-card">
                    <h3>روابط التواصل</h3>
                    <ul class="mg-user-links">
                        @foreach($socialLinks as $link)
                            <li>
                                <span>{{ \App\Models\SocialLink::PLATFORMS[$link->platform] ?? $link->platform }}</span>
                                <a href="{{ $link->url }}" target="_blank" rel="noopener">{{ $link->url }}</a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if($record->about_me || $record->short_description || $record->long_description || $record->store_description)
                <section class="mg-user-card">
                    <h3>{{ $isPlumber ? 'نبذة السباك' : 'نبذة تعريفية' }}</h3>
                    @if($record->short_description)
                        <div class="mg-user-about"><strong>مختصر</strong><p>{{ $record->short_description }}</p></div>
                    @endif
                    @if($record->about_me)
                        <div class="mg-user-about"><strong>نبذة</strong><p>{{ $record->about_me }}</p></div>
                    @endif
                    @if($record->long_description)
                        <div class="mg-user-about"><strong>تفاصيل</strong><p>{{ $record->long_description }}</p></div>
                    @endif
                    @if($record->store_description)
                        <div class="mg-user-about"><strong>وصف المتجر</strong><p>{{ $record->store_description }}</p></div>
                    @endif
                </section>
            @endif
        </div>
    @endif

    @if($isPlumber && $finance)
        <section class="mg-user-card mg-user-card--wide">
            <h3>المحفظة والسحوبات</h3>
            <div class="mg-user-finance-sum">
                <div><span>مكتسب</span><strong>{{ number_format($finance['earned_points']) }} نقطة</strong></div>
                <div><span>محوّل لفلوس</span><strong>{{ number_format($finance['converted_points']) }} نقطة</strong></div>
                <div><span>سحوبات مدفوعة</span><strong>{{ $finance['withdrawals_paid_count'] }} طلب</strong></div>
                <div><span>سحوبات معلّقة</span><strong>{{ $finance['withdrawals_pending_count'] }} طلب</strong></div>
            </div>

            <h4 class="mg-user-subh">طلبات السحب</h4>
            @if($finance['withdrawals']->isEmpty())
                <p class="mg-user-empty">لا توجد طلبات سحب</p>
            @else
                <div class="mg-user-table-wrap">
                    <table class="mg-user-table">
                        <thead>
                            <tr><th>#</th><th>المبلغ</th><th>الحالة</th><th>الطريقة</th><th>التاريخ</th></tr>
                        </thead>
                        <tbody>
                            @foreach($finance['withdrawals'] as $w)
                                @php
                                    $badge = match ($w->status) {
                                        'paid' => 'ok',
                                        'pending', 'approved' => 'warn',
                                        'rejected' => 'danger',
                                        default => 'gray',
                                    };
                                @endphp
                                <tr>
                                    <td>
                                        <strong>#{{ $w->id }}</strong>
                                        @if($w->receipt_number)<div class="mg-user-muted">{{ $w->receipt_number }}</div>@endif
                                    </td>
                                    <td><strong>{{ $w->formattedAmount() }}</strong></td>
                                    <td><span class="mg-user-badge mg-user-badge--{{ $badge }}">{{ $w->statusLabel() }}</span></td>
                                    <td>
                                        <div>{{ $w->methodLabel() }}</div>
                                        <div class="mg-user-muted">{{ $w->payoutDetailsSummary() }}</div>
                                    </td>
                                    <td class="mg-user-muted">{{ optional($w->paid_at ?? $w->created_at)->format('Y/m/d H:i') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <h4 class="mg-user-subh">حركات المحفظة</h4>
            @if($finance['transactions']->isEmpty())
                <p class="mg-user-empty">لا توجد حركات</p>
            @else
                <div class="mg-user-table-wrap">
                    <table class="mg-user-table">
                        <thead>
                            <tr><th>النوع</th><th>الوصف</th><th>نقاط</th><th>مبلغ</th><th>التاريخ</th></tr>
                        </thead>
                        <tbody>
                            @foreach($finance['transactions'] as $tx)
                                @php
                                    $typeBadge = match ($tx->type) {
                                        'credit' => 'ok',
                                        'conversion' => 'info',
                                        'withdrawal' => 'warn',
                                        'adjustment' => 'gray',
                                        'debit' => 'danger',
                                        default => 'gray',
                                    };
                                    $pts = (int) $tx->points_delta;
                                    $cents = (int) $tx->amount_cents;
                                @endphp
                                <tr>
                                    <td><span class="mg-user-badge mg-user-badge--{{ $typeBadge }}">{{ $financeService->transactionTypeLabel($tx->type) }}</span></td>
                                    <td>
                                        <strong>{{ $tx->description ?: '—' }}</strong>
                                        @if(! empty($tx->meta['reason']))
                                            <div class="mg-user-muted">{{ $tx->meta['reason'] }}</div>
                                        @endif
                                    </td>
                                    <td class="{{ $pts >= 0 ? 'is-pos' : 'is-neg' }}">{{ $pts > 0 ? '+' : '' }}{{ number_format($pts) }}</td>
                                    <td class="{{ $cents >= 0 ? 'is-pos' : 'is-neg' }}">
                                        @if($cents !== 0)
                                            {{ ($cents > 0 ? '+' : '').number_format($cents / 100, 2) }} د.ل
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="mg-user-muted">{{ optional($tx->created_at)->format('Y/m/d H:i') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif

    @if($record->video_url)
        <section class="mg-user-card mg-user-card--wide">
            <h3>فيديو تعريفي</h3>
            <div class="mg-user-video">
                <video src="{{ $record->video_url }}" controls playsinline></video>
            </div>
        </section>
    @endif

    @if($isPlumber)
        <section class="mg-user-card mg-user-card--wide">
            <h3>معرض الأعمال</h3>
            @include('filament.forms.plumber-work-gallery', ['galleryRecord' => $record])
        </section>
    @endif
</div>
