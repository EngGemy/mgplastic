# المحفظة والسحب

## المحفظة (`WalletAccount`)
- لكل مستخدم محفظة (عملة افتراضية LYD).
- `balance_points` رصيد النقاط.
- `balance_cents` رصيد نقدي بعد التحويل.
- الحركات في `wallet_transactions`.

## التحويل
`PointsConversionService` + إعدادات `ConversionRule` العالمية تحدد قيمة النقطة وفترة الصرف والإشعارات.

## طلبات السحب (`WithdrawalRequest`)
- حالات: `pending`, `approved`, `paid`, `rejected` (حسب التنفيذ).
- مرتبطة بالسباك غالباً.
- بعد الدفع قد يُرسل إشعار + إيصال موقّع.

## للأدمن
استخدم أدوات AI: `list_withdrawal_requests`, `report_points`, `get_user` لمراجعة الرصيد قبل اتخاذ إجراء يدوي في اللوحة.
