<?php

namespace App\Services\Ocr;

use RuntimeException;

class OcrSupplierNotAllowedException extends RuntimeException
{
    public function __construct(string $message = 'غير مسموح باستخدام هذا المورّد في OCR')
    {
        parent::__construct($message);
    }

    public function messageCode(): string
    {
        return 'ocr_supplier_not_allowed';
    }
}
