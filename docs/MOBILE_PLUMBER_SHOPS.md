# محلات القطاعي للسباك — أقرب · بحث · فلاتر · مفضلة

> الباك إند جاهز. استخدم هذا الملف لربط شاشة اختيار المحل في تطبيق السباك.

**Auth:** `Authorization: Bearer {token}`  
**دور السباك فقط يرى محلات قطاعي** (لا جملة / مخزن / مصنع).

مرتبط: موقع السباك من البروفايل (`latitude` / `longitude`) · طلب منتجات المحل `GET …/plumber/products?retail_trader_id=`

---

## 1) قائمة المحلات (المسار الموصى به)

```http
GET /api/v1/mobile/plumber/shops
```

بديل عام (نفس المنطق مع Bearer السباك):

```http
GET /api/v1/network-stores
GET /api/v1/vendors
```

### Query params

| Param | مثال | المعنى |
|-------|------|--------|
| `lat` / `lng` أو `latitude` / `longitude` | `32.88` / `13.18` | موقع الجهاز الآن (الأفضل للترتيب الأقرب) |
| `sort` | `nearest` \| `newest` \| `name` | الافتراضي `nearest` لو فيه موقع، وإلا `newest` |
| `q` أو `search` | `النجار` | بحث بالاسم / البراند / الهاتف / كود الشبكة / العنوان |
| `city_id` | `3` | فلتر مدينة |
| `country_id` | `1` | فلتر دولة |
| `favorites_only` | `1` | المفضلة فقط |
| `has_location` | `1` | محلات ليها إحداثيات فقط |
| `page` / `per_page` | `1` / `15` | ترقيم (أقصى 50) |

### أولوية موقع الترتيب

1. `lat`+`lng` من الطلب (GPS الجهاز)  
2. وإلا إحداثيات بروفايل السباك  
3. وإلا مفيش `nearest` → يرجع `newest`

حدّث موقع السباك عند فتح الشاشة (اختياري لكن مفيد بدون GPS كل مرة):

```http
POST /api/v1/plumber/update-profile
{ "latitude": 32.88, "longitude": 13.18 }
```

### مثال طلب

```bash
curl -H "Authorization: Bearer …" \
  "https://mg-plastic.com/api/v1/mobile/plumber/shops?sort=nearest&lat=32.887&lng=13.191&q="
```

### شكل الرد

```json
{
  "status": true,
  "message": "محلات القطاعي القريبة منك",
  "data": [
    {
      "id": 55,
      "brand_name": "محل النجار",
      "name": "…",
      "phone": "09…",
      "role": "retail_trader",
      "address": "…",
      "latitude": 32.89,
      "longitude": 13.19,
      "distance_km": 1.24,
      "is_favorite": false,
      "city": { "id": 3, "name_ar": "طرابلس" },
      "profile_photo_url": "…",
      "brand_logo_url": "…"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 15,
    "total": 12,
    "last_page": 1,
    "listed_role": "retail_trader",
    "viewer_role": "plumber",
    "sort": "nearest",
    "origin": {
      "latitude": 32.887,
      "longitude": 13.191,
      "source": "query"
    },
    "filters": {
      "sort": ["nearest", "newest", "name"],
      "q": null,
      "city_id": null,
      "country_id": null,
      "favorites_only": false,
      "has_location": false
    }
  }
}
```

| حقل | استخدام في UI |
|-----|----------------|
| `distance_km` | «يبعد 1.2 كم» — `null` لو مفيش موقع للمحل أو للسباك |
| `is_favorite` | قلب المفضلة |
| `meta.origin.source` | `query` \| `profile` \| `none` |
| `meta.filters.sort` | خيارات شريط الفلتر |

---

## 2) Checklist شاشة الموبايل

- [ ] اطلب إذن الموقع → مرّر `lat`+`lng` مع `sort=nearest`
- [ ] شريط بحث → `q=`
- [ ] فلاتر: ترتيب (`nearest`/`newest`/`name`) · مدينة · «المفضلة فقط» · «ليها موقع»
- [ ] زر قلب على الكارت → إضافة/إزالة مفضلة
- [ ] تبويب أو شاشة «مفضلتي» → `GET …/favorite-stores` أو `favorites_only=1`
- [ ] الضغط على محل → كتالوج الطلب `…/plumber/products?retail_trader_id={id}`
- [ ] لو `origin.source = none` اعرض تلميح «فعّل الموقع لترتيب الأقرب»

---

## 3) المفضلة

```http
GET    /api/v1/mobile/plumber/favorite-stores
POST   /api/v1/mobile/plumber/favorite-stores/{id}
DELETE /api/v1/mobile/plumber/favorite-stores/{id}
```

`{id}` = رقم المحل أو `network_code`.

### إضافة

```json
{
  "status": true,
  "message": "تمت إضافة المحل للمفضلة",
  "data": {
    "store": { "id": 55, "is_favorite": true, "…": "…" },
    "is_favorite": true
  }
}
```

### حذف

```json
{
  "data": { "store_id": 55, "is_favorite": false }
}
```

في قائمة المحلات بعد الإضافة: حدّث `is_favorite` محلياً أو أعد الجلب.

---

## 4) تفاصيل محل واحد

```http
GET /api/v1/network-stores/{id}?lat=…&lng=…
```

يرجع نفس كارت المحل مع `distance_km` و`is_favorite` لو فيه توكن.

---

## 5) اختبار يدوي سريع

1. سباك بإحداثيات + محلين قطاعي بإحداثيات مختلفة → `sort=nearest` يرتّب الأقرب أولاً  
2. `q=اسم` يفلتر  
3. `POST favorite-stores/{id}` ثم `favorites_only=1` يظهر المحل  
4. جملة / مخزن **ما يظهروش** في قائمة السباك  

---

## ملخص Endpoints

| الغرض | Method | Path |
|-------|--------|------|
| قائمة + فلاتر | GET | `/api/v1/mobile/plumber/shops` |
| نفس القائمة (عام) | GET | `/api/v1/network-stores` |
| مفضلتي | GET | `/api/v1/mobile/plumber/favorite-stores` |
| إضافة مفضلة | POST | `/api/v1/mobile/plumber/favorite-stores/{id}` |
| حذف مفضلة | DELETE | `/api/v1/mobile/plumber/favorite-stores/{id}` |
