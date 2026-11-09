# OCR golden set

Put anonymized receipt images here with sibling `.json` expected files:

```json
{
  "lines": [
    { "name": "كوع 90 1 بوصة", "qty": 4, "product_id": 42 }
  ]
}
```

Run:

```bash
php artisan ocr:eval
# or
php artisan ocr:evaluate tests/Fixtures/ocr-golden
```
