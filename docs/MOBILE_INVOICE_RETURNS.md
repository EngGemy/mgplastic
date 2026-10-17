# Mobile — Invoice returns & product kinds

**Production:** `https://mg-plastic.com/api`  
**Auth:** `Authorization: Bearer {token}`

Returns are allowed on:

| Hop | Who | Endpoint |
|-----|-----|----------|
| جملة → قطاعي (tier 2) | جملة أو قطاعي (طرف الفاتورة) | Distributor / Trader invoice returns |
| قطاعي → سباك (tier 3) | قطاعي أو سباك (طرف التوزيع) | Trader distribution returns |

**Blocked:** مرتجع بعد صرف/تحويل نقاط السباك من نفس التوزيع.  
**Tier 2:** لا يحرّك محفظة النقاط (مخزون/صافي فقط).  
**Tier 3:** يخصم من محفظة السباك ويعيد نفس العدد للقطاعي — بدون تضخيم نقاط.

---

## Distributor (جملة)

```http
GET  /api/v1/mobile/distributor/returns
GET  /api/v1/mobile/distributor/invoices/{invoice}/return-preview
POST /api/v1/mobile/distributor/invoices/{invoice}/returns
GET  /api/v1/mobile/distributor/returns/{return}
```

```json
{
  "lines": [
    { "invoice_item_id": 12, "quantity": 2 }
  ],
  "note": "تالف"
}
```

Preview includes `can_return`, `block_reason`, `lines[].returnable`.

---

## Trader (قطاعي)

```http
GET  /api/v1/mobile/trader/returns
GET  /api/v1/mobile/trader/distributions/{distribution}/return-preview
POST /api/v1/mobile/trader/distributions/{distribution}/returns
GET  /api/v1/mobile/trader/invoices/{invoice}/return-preview
POST /api/v1/mobile/trader/invoices/{invoice}/returns
GET  /api/v1/mobile/trader/returns/{return}
```

Use **distributions** for plumber sales (tier 3).  
Use **invoices** for purchases from wholesale (tier 2).

---

## Error examples (422)

| Message (ar) | Meaning |
|--------------|---------|
| لا يمكن عمل مرتجع بعد صرف النقاط — … | Plumber converted/spent points |
| لا يمكن عمل مرتجع بعد صرف نقاط هذه الفاتورة — … | Lots from this distribution spent |
| كمية المرتجع (…) تتجاوز المتاح للإرجاع | Qty too high / already pushed down |
| مرتجع الجملة→قطاعي متاح على فواتير الصادر… | Wrong invoice type |
| غير مصرّح… | Not a party on the document |

---

## Product kinds (catalog / cart)

| `product_kind` | `is_mg` | `has_points` / `awards_points` | Meaning |
|----------------|---------|--------------------------------|---------|
| `mg_points` | true | true | MG product with loyalty points |
| `mg_no_points` | true | false | MG catalog item — **orderable, 0 points** |
| `shop` | false | false | Store `my_product` (display / shop catalog) |

Filter catalog: `?has_points=1` or `?has_points=0`.

Shop `my_products` on network store profiles already expose `product_kind: shop` and `orderable_without_points: true`. Network stock POS/orders use MG catalog products (`mg_points` / `mg_no_points`).
