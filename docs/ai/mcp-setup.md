# MG Plastic — Remote MCP Server (Streamable HTTP)

هذا MCP حقيقي على السيرفر (مش OpenAPI Actions فقط). بعد الرفع تحط **لينك واحد** + التوكن.

## اللينك

```
https://mg-plastic.com/mcp
```

Alias قديم (نفس السيرفر):

```
https://mg-plastic.com/api/v1/ai/mcp
```

## التوثيق (Auth)

```http
Authorization: Bearer <AI_GATEWAY_TOKEN>
```

ضع `AI_GATEWAY_TOKEN` في `.env` على السيرفر (سري طويل عشوائي).

## على السيرفر بعد الرفع

```env
AI_GATEWAY_TOKEN=long-random-secret
AI_TOOLS_ALLOW_WRITE=false
AI_GATEWAY_RATE_LIMIT=60
```

```bash
php artisan migrate --force
php artisan optimize:clear
```

اختبار:

```bash
curl -s -H "Authorization: Bearer YOUR_TOKEN" https://mg-plastic.com/mcp
```

ثم:

```bash
curl -s -X POST https://mg-plastic.com/mcp \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"curl","version":"1.0"}}}'
```

```bash
curl -s -X POST https://mg-plastic.com/mcp \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}'
```

## ChatGPT (Custom MCP / Plugins)

1. ChatGPT → Plugins / Add custom MCP server  
2. URL: `https://mg-plastic.com/mcp`  
3. Auth: Bearer → الصق `AI_GATEWAY_TOKEN`  
4. احفظ وجرّب: «ورّيني ملخص اللوحة» أو `dashboard_summary`

> ملاحظة: **Custom GPT Actions** (ملف OpenAPI) شيء مختلف. الـ MCP هو اللينك أعلاه مباشرة.

## Cursor

في `mcp.json`:

```json
{
  "mcpServers": {
    "mg-plastic-admin": {
      "url": "https://mg-plastic.com/mcp",
      "headers": {
        "Authorization": "Bearer YOUR_TOKEN_HERE"
      }
    }
  }
}
```

## الأدوات (أهمها)

| أداة | تستخدمها لـ |
|------|-------------|
| `list_products` / `get_product` | آخر منتج / أسماء المنتجات |
| `list_stores` / `get_store` | متاجر جملة/قطاعي + مخزون |
| `list_plumbers` | السباكين + نقاطهم |
| `list_point_balances` | أعلى أرصدة نقاط |
| `list_wallet_transactions` | آخر حركة نقاط |
| `list_recent_distributions` | آخر توزيعات + أسماء المنتجات الموزعة |
| `list_orders` | آخر الطلبيات |
| `list_recent_activity` | فيد موحّد لآخر التحديثات |
| `dashboard_summary` / `report_*` | ملخصات وتقارير |
| `search_users` / `get_user` / `list_pending_approvals` | مستخدمين وموافقات |
| `search_invoices` / `get_invoice` | فواتير |
| `list_withdrawal_requests` | طلبات السحب |

الكتابة (`approve_user_or_store` …) تشتغل فقط إذا `AI_TOOLS_ALLOW_WRITE=true`.
