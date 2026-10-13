# Product module — categories, tags, and filters

Public catalog for the mobile app. No login token.

**Production base:** `https://mg-plastic.com/api`  
**Local base:** `http://127.0.0.1:8000/api`

Send this header on every call. `ar` and `en` are the only languages. Anything else falls back to English.

```http
Accept: application/json
Accept-Language: ar
```

`name`, `description`, `usage`, and `category_name` follow that language.

---

## How the catalog is organized

- A **category** is a top-level group (`parent_id` is `null`). Example: صرف.
- A **subcategory** is a category whose `parent_id` is the parent category. Example: أكواع.
- A **tag** is a short label stored on a category or subcategory. The same name can be suggested from the parent and from the subcategory.
- A **product** belongs to one category (`product_category_id`, usually the subcategory) and has its own `tags` array.

`tags` in every response is a list of strings, not objects.

```json
"tags": ["صرف", "كوع"]
```

A category’s `tags` are only the tags saved on that category. They are not copied onto the parent or the children. To build filter chips for a subcategory, merge `parent.tags` and `child.tags` in the app and drop duplicates.

A product’s `tags` are the tags attached to that product. Filtering products by tag uses this list.

---

## Suggested app flow

1. `GET /api/v1/products/categories` — show the parent categories.
2. User opens one parent — use its `children`, or call `?parent_id={id}`.
3. Show tag chips from the selected category and, if it has a parent, from that parent too.
4. `GET /api/v1/products` with `category_id`, `parent_id`, `tag`, and `search`.
5. Open one product with `GET /api/v1/products/{id}`.

---

## 1. Categories

```http
GET /api/v1/products/categories
```

No query: a tree. `data` is the parent categories. Each parent includes `children`.

### Query

| Query | Required | Meaning |
|-------|----------|---------|
| `search` or `q` | no | Category name or description contains this text |
| `tag` or `tags` | no | Category has this tag. Several tags: comma, Arabic comma `،`, or repeated `tags[]` |
| `parent_id` | no | One parent plus its subcategories. Changes the JSON shape |
| `include_empty` | no | Default `true`. `false` hides categories that have no products |

Several tags mean **any** of them (OR).

Search and tag keep the tree shape: a matching subcategory is returned under its parent, and `children` contains only the matches.

### Tree response

```json
{
  "status": true,
  "data": [
    {
      "id": 4,
      "parent_id": null,
      "slug": "drainage",
      "accepted_language": "ar",
      "name": "صرف",
      "description": null,
      "image": "https://mg-plastic.com/storage/categories/drainage.jpg",
      "products_count": 12,
      "tags": ["ضغط"],
      "children": [
        {
          "id": 9,
          "parent_id": 4,
          "slug": "elbows",
          "accepted_language": "ar",
          "name": "أكواع",
          "description": null,
          "image": null,
          "products_count": 3,
          "tags": ["كوع"],
          "children": []
        }
      ]
    }
  ]
}
```

### One parent and its subcategories

`parent_id` wraps the result. Do not read `data` as a list in this mode.

```http
GET /api/v1/products/categories?parent_id=4&tag=كوع
```

```json
{
  "status": true,
  "data": {
    "parent": { "id": 4, "parent_id": null, "name": "صرف", "tags": ["ضغط"], "children": [] },
    "children": [
      { "id": 9, "parent_id": 4, "name": "أكواع", "tags": ["كوع"], "products_count": 3, "children": [] }
    ]
  }
}
```

Unknown `parent_id`:

```json
{ "status": false, "message": "Parent category not found" }
```

HTTP 404.

### Examples

```http
GET /api/v1/products/categories?search=أكواع
GET /api/v1/products/categories?tags=كوع,ضغط
GET /api/v1/products/categories?include_empty=false
```

---

## 2. Filter products

```http
GET /api/v1/products
```

Always paginated. Default page size is 20. Maximum is 100.

### Query

| Query | Required | Meaning |
|-------|----------|---------|
| `category_id` or `product_category_id` | no | Products in this category only |
| `with_children` | no | With `category_id`. `1` or `true` also includes direct subcategories |
| `parent_id` | no | Used when `category_id` is absent. Products in that category and its direct subcategories |
| `tag` or `tags` | no | Product has one of these tags |
| `search` or `q` | no | Name, description, usage, category name, tag, grade, notes, or numeric product id |
| `classification` | no | Exact grade, for example `premium` or `standard` |
| `standard_id` | no | Product standard id |
| `color_id` | no | Product color id |
| `per_page` | no | 1–100. Default 20 |
| `page` | no | Page number. Default 1 |

Filters combine with AND. Tags inside `tags` combine with OR.

`classification` filters the list. It is not a field on the product object below.

### Response

```json
{
  "status": true,
  "data": [
    {
      "id": 15,
      "accepted_language": "ar",
      "name": "كوع 90",
      "description": "",
      "usage": "شبكات الصرف",
      "product_category_id": 9,
      "category_name": "أكواع",
      "tags": ["كوع"],
      "product_standard_id": null,
      "standard_name": null,
      "product_color_id": null,
      "color_name": null,
      "main_image": "https://mg-plastic.com/storage/products/main/elbow.png",
      "main_image_url": "https://mg-plastic.com/storage/products/main/elbow.png",
      "images": [
        { "id": 3, "path": "products/gallery/a.jpg", "url": "https://mg-plastic.com/storage/products/gallery/a.jpg", "sort": 0 }
      ],
      "meta": {},
      "created_at": "2026-10-12T15:14:40.000000Z",
      "updated_at": "2026-10-12T15:14:40.000000Z",
      "type": "standard",
      "varients": [
        { "key": "length_m", "title": "الطول (متر)", "title_en": "Length (m)", "title_ar": "الطول (متر)", "value": 4 }
      ],
      "notes": null,
      "variants": [],
      "sizes": []
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 6,
    "per_page": 20,
    "total": 110
  }
}
```

`type` is `standard` or `accessory`.

`varients` is the spelling returned by the API. It holds length, thickness, and volume for a standard product. Accessories return `varients: []` and put options in `sizes`.

### Examples

```http
GET /api/v1/products?parent_id=4&per_page=20
GET /api/v1/products?category_id=9&tag=كوع
GET /api/v1/products?category_id=4&with_children=1
GET /api/v1/products?search=ماسورة&classification=standard&page=2
GET /api/v1/products?tags=كوع,صرف
```

---

## 3. Products in one category

```http
GET /api/v1/products/category/{id}
```

Same product object as the filter endpoint.

| Query | Required | Meaning |
|-------|----------|---------|
| `with_children` | no | Default `false`. `true` includes products in direct subcategories |
| `tag` or `tags` | no | Same as the product filter |
| `search` or `q` | no | Same as the product filter |
| `classification` | no | Exact grade |
| `standard_id` | no | Standard id |
| `color_id` | no | Color id |
| `per_page` | no | If omitted or `0`, `data` is the full list and there is no `meta`. If greater than 0, the response is paginated and includes `meta` |

Unknown category: HTTP 404, `{ "status": false, "message": "Category not found" }`.

```http
GET /api/v1/products/category/9?tag=كوع&per_page=20
GET /api/v1/products/category/4?with_children=1
```

---

## 4. One product

```http
GET /api/v1/products/{id}
```

Same fields as a list item, plus HTML and catalog files.

```json
{
  "status": true,
  "data": {
    "id": 15,
    "accepted_language": "ar",
    "name": "كوع 90",
    "description": "نص بدون وسوم",
    "usage": "شبكات الصرف",
    "description_html": "<p>نص</p>",
    "usage_html": "<p>شبكات الصرف</p>",
    "product_category_id": 9,
    "category_name": "أكواع",
    "tags": ["كوع"],
    "product_standard_id": null,
    "standard_name": null,
    "product_color_id": null,
    "color_name": null,
    "main_image": "https://mg-plastic.com/storage/products/main/elbow.png",
    "main_image_url": "https://mg-plastic.com/storage/products/main/elbow.png",
    "catalog": {
      "image": { "path": null, "url": null, "mime": null, "size": null },
      "pdf": { "path": null, "url": null, "mime": null, "size": null },
      "has_image": false,
      "has_pdf": false,
      "image_url": null,
      "pdf_url": null
    },
    "images": [],
    "meta": {},
    "created_at": "2026-10-12T15:14:40.000000Z",
    "updated_at": "2026-10-12T15:14:40.000000Z",
    "type": "standard",
    "varients": [],
    "variants": [],
    "notes": null,
    "sizes": []
  }
}
```

Use `description` and `usage` for plain text. Use `description_html` and `usage_html` only if the screen renders HTML.

Missing product: HTTP 404.

---

## Notes for the app

- These routes are public. Do not send `Authorization` unless you are calling a different module.
- Encode Arabic query values. `tag=كوع` is `tag=%D9%83%D9%88%D8%B9`.
- An empty `tags` array means the product or category has no tags yet.
- Image fields are absolute URLs, or `null`.
- `children` on a category is `[]` when that node has no subcategories in the current result. On a filtered tree, a parent can appear with only the matching children.
