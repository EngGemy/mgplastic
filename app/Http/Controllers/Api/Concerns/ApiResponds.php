<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Http\JsonResponse;

trait ApiResponds
{
    protected function success(mixed $data = null, string $message = 'OK', int $code = 200, ?string $messageCode = null): JsonResponse
    {
        $payload = [
            'status' => true,
            'message' => $message,
            'message_code' => $messageCode ?? $this->inferMessageCode($message, true),
            'data' => $data,
        ];

        return response()->json($payload, $code);
    }

    protected function created(mixed $data = null, string $message = 'Created', ?string $messageCode = null): JsonResponse
    {
        return $this->success($data, $message, 201, $messageCode ?? $this->inferMessageCode($message, true));
    }

    protected function error(string $message, int $code = 400, mixed $errors = null, ?string $errorCode = null, ?string $messageCode = null): JsonResponse
    {
        $payload = [
            'status' => false,
            'message' => $message,
            'message_code' => $messageCode ?? $errorCode ?? $this->inferMessageCode($message, false),
        ];

        if ($errorCode !== null) {
            $payload['error_code'] = $errorCode;
        }

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $code);
    }

    protected function inferMessageCode(string $message, bool $ok): string
    {
        $map = [
            'OK' => 'ok',
            'Created' => 'created',
            'لا توجد دفعة معلّقة' => 'ocr_no_pending_batch',
            'دفعة OCR معلّقة' => 'ocr_pending_batch',
            'دفعة OCR جاهزة' => 'ocr_batch_ready',
            'بدأت قراءة الصورة' => 'ocr_image_reading',
            'بدأت تحليل النص' => 'ocr_paste_started',
            'أُعيدت المحاولة' => 'ocr_image_retried',
            'أُلغيت الصورة' => 'ocr_image_canceled',
            'بدأت إعادة القراءة' => 'ocr_reread_started',
            'تم تجاهل الدفعة' => 'ocr_batch_abandoned',
            'تم تحديث الإعدادات' => 'ocr_prefs_updated',
            'تم تحديث البند' => 'ocr_line_updated',
            'تم اعتماد البنود الجاهزة' => 'ocr_approve_ready_done',
            'بيانات غير صالحة' => 'validation_failed',
            'الدفعة غير موجودة' => 'ocr_batch_not_found',
            'الصورة غير موجودة' => 'ocr_image_not_found',
            'البند غير موجود' => 'ocr_line_not_found',
            'المنتج غير موجود' => 'product_not_found',
            'اختَر منتجاً أولاً' => 'ocr_pick_product_first',
            'النص مطلوب' => 'ocr_text_required',
            'صورة غير صالحة' => 'ocr_invalid_image',
            'الصورة مرفوعة مسبقاً في نفس الدفعة' => 'ocr_duplicate_image',
            'تم تطبيق هذه الدفعة مسبقاً — لن تُكرَّر البنود' => 'ocr_already_applied',
            'لا بنود معتمدة — اعتمد البنود ثم طبّق على السلة' => 'ocr_no_approved_lines',
            'قراءة الفواتير غير مفعّلة على السيرفر (GEMINI_API_KEY)' => 'ocr_gemini_disabled',
            'هذه الدفعة مغلقة — أنشئ دفعة جديدة' => 'ocr_batch_closed',
            'لا يوجد مرشّح لهذا النظام' => 'ocr_no_system_candidate',
            'الدفعة مُقفلة بعد الإضافة للسلة' => 'ocr_batch_locked',
            'مخزون الجملة — مقاسات مرتّبة' => 'pos_stock_ok',
            'تم إصدار الفاتورة — بانتظار تأكيد استلام القطاعي' => 'pos_checkout_pending_retail',
            'تم إصدار الفاتورة — بانتظار تأكيد استلام السباك' => 'pos_checkout_pending_plumber',
        ];

        foreach ($map as $ar => $code) {
            if ($message === $ar || str_starts_with($message, $ar)) {
                return $code;
            }
        }

        // Dynamic "تم تجهيز N منتج للسلة"
        if (str_starts_with($message, 'تم تجهيز ')) {
            return 'ocr_apply_ok';
        }

        return $ok ? 'ok' : 'error';
    }
}
