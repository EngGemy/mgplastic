# مجموعات المنتجات · البانرات · فواتيري · مخزن=مصنع

## 1) المصنع والمخزن = نفس المنتجات

`GET /api/v1/mobile/distributor/warehouses/{id}/stock` يرجع **نفس كتالوج المصنع** مع:

| حقل | معنى |
|-----|------|
| `same_as_factory` | `true` |
| `available_qty` | كمية مسجّلة في المخزن، أو `null` = نفس عرض المصنع |
| `product_id` / `id` | نفس منتجات `GET …/distributor/products` |

`GET order-sources` يضيف `catalog_url` + `same_inventory_as_factory` للمخزن.

---

## 2) مجموعات المنتجات (لوحة + موبايل)

**لوحة التحكم → المحتوى → مجموعات المنتجات**  
أنواع: مميزة · حديثة · أهم المنتجات · مخصّصة.

| Method | Path |
|--------|------|
| GET | `/api/v1/mobile/product-collections` |
| GET | `/api/v1/mobile/product-collections/{id}` |
| GET | `/api/v1/mobile/product-collections?kind=featured` |

---

## 3) بانرات دعائية (بين السباكين والمتاجر)

**لوحة التحكم → المحتوى → بانرات دعائية**  
`section = between_plumbers_stores`  
الرابط: بدون · URL · مجموعة منتجات · إعلان MG · منتج.

```http
GET /api/v1/mobile/promo-banners?section=between_plumbers_stores
```

كل عنصر فيه `link: { type, id?, url?, path? }`.

---

## 4) فواتيري

### جملة
```http
GET /api/v1/mobile/distributor/my-invoices
GET /api/v1/mobile/distributor/invoices?scope=all|sales|purchases
```

| bucket | معنى | مرتجع؟ |
|--------|------|--------|
| `purchases` | وارد من مصنع/مخزن | نعم — الجملة يطلب |
| `sales` | صادر للقطاعي | لا — ينتظر طلب القطاعي ثم `approve` |

مرتجع المشتريات:
```http
GET  …/distributor/invoices/{id}/return-preview   # فاتورة وارد
POST …/distributor/invoices/{id}/returns
# أو
GET  …/distributor/distributions/{id}/return-preview
POST …/distributor/distributions/{id}/returns
```

### قطاعي
```http
GET /api/v1/mobile/trader/my-invoices
GET /api/v1/mobile/trader/invoices
```
مشتريات من الجملة — يطلب المرتجع ثم الجملة توافق.

### مشاركة واتساب (جملة + قطاعي + سباك)
كل صف فاتورة يتضمن `can_share_whatsapp` · `share_url` · `whatsapp_url`.

```http
GET  /api/v1/mobile/{trader|distributor}/invoices/{id}/share
POST /api/v1/mobile/{trader|distributor}/invoices/{id}/share-whatsapp
GET|POST /api/v1/mobile/{trader|distributor|plumber}/distributions/{id}/share-whatsapp
```

افتح `whatsapp_url` في التطبيق → محادثة صاحب الرقم بنص + رابط الفاتورة.  
تفاصيل كاملة: [MOBILE_UPDATES_OCT19_20.md](./MOBILE_UPDATES_OCT19_20.md)
