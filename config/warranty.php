<?php

return [
    'enabled' => filter_var(env('WARRANTY_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    'min_level' => env('WARRANTY_MIN_LEVEL', 'gold'),
    'min_score' => (int) env('WARRANTY_MIN_SCORE', 70),
    'min_distributions' => (int) env('WARRANTY_MIN_DISTRIBUTIONS', 5),
    'default_months' => (int) env('WARRANTY_DEFAULT_MONTHS', 12),
    'terms_ar' => 'هذا الضمان يغطي عيوب التصنيع للمنتجات المدرجة وفق شروط MG للصناعات البلاستيكية. لا يشمل سوء التركيب أو الاستخدام غير الصحيح.',
    'daily_issue_cap' => 10,
    'resend_cooldown_hours' => 24,
];
