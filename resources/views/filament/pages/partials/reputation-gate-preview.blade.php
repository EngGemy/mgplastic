@php
    $levelLabel = (string) data_get($levels, $minLevel.'.label', $minLevel);
    $levelMin = (int) data_get($levels, $minLevel.'.min', 0);
    $checks = [
        ['label' => 'المستوى', 'value' => $levelLabel, 'hint' => "من {$levelMin}+ درجة"],
        ['label' => 'الدرجة', 'value' => $minScore.'/100', 'hint' => 'بعد إعادة الحساب'],
        ['label' => 'التوزيعات', 'value' => (string) $minDistributions, 'hint' => 'مؤكدة فقط'],
        ['label' => 'مدة الضمان', 'value' => $months.' شهر', 'hint' => 'للشهادات الجديدة'],
    ];
@endphp

<div class="mg-rep-gate" dir="rtl">
    <div class="mg-rep-gate__rail">
        <span class="mg-rep-gate__kicker">معاينة الأهلية</span>
        <h3 class="mg-rep-gate__title">متى يُفتح الضمان؟</h3>
        <p class="mg-rep-gate__hint">تتحدّث مع الحقول أعلاه — هذه هي البوابة التي يمرّ بها السباك قبل إصدار شهادة لعميله.</p>
    </div>

    <div class="mg-rep-gate__card">
        <div class="mg-rep-gate__seal" aria-hidden="true">
            <span>🛡️</span>
        </div>
        <div class="mg-rep-gate__body">
            <p class="mg-rep-gate__lead">يجب أن يستوفي السباك <strong>كل</strong> الشروط معًا:</p>
            <ul class="mg-rep-gate__list">
                @foreach ($checks as $check)
                    <li>
                        <span class="mg-rep-gate__check-label">{{ $check['label'] }}</span>
                        <span class="mg-rep-gate__check-value">{{ $check['value'] }}</span>
                        <span class="mg-rep-gate__check-hint">{{ $check['hint'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</div>
