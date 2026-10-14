<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ArabicValidationTest extends TestCase
{
    public function test_validation_messages_are_arabic(): void
    {
        app()->setLocale('ar');

        $messages = Validator::make([
            'password' => '123',
            'email' => 'not-an-email',
        ], [
            'name' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'min:6'],
        ])->errors();

        $this->assertSame('الاسم مطلوب.', $messages->first('name'));
        $this->assertSame('أدخل بريداً إلكترونياً صحيحاً في البريد الإلكتروني.', $messages->first('email'));
        $this->assertSame('يجب أن يكون كلمة المرور 6 أحرف على الأقل.', $messages->first('password'));

        app()->setLocale('en');

        $englishUi = Validator::make([], [
            'phone' => ['required'],
        ])->errors();

        $this->assertSame('رقم الهاتف مطلوب.', $englishUi->first('phone'));
    }
}
