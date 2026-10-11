# MG Admin AI Gateway

Laravel-hosted tools for **remote MCP** (recommended) and ChatGPT Custom GPT Actions.

## أقوى طريقة: Remote MCP (لينك واحد)

بعد الرفع حط بس:

```
https://mg-plastic.com/mcp
```

مع `Authorization: Bearer <AI_GATEWAY_TOKEN>`

تفاصيل كاملة: [mcp-setup.md](mcp-setup.md)

## REST / Actions (اختياري)
Base: `/api/v1/ai`

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/health` | Health + write flag |
| GET | `/tools` | List tools |
| POST | `/call` | `{ "tool": "...", "arguments": {} }` |
| POST | `/tools/{tool}` | Call tool; body = arguments |
| GET/POST | `/mcp` | Legacy MCP path (same server as `/mcp`) |

Auth header: `Authorization: Bearer <AI_GATEWAY_TOKEN>`

## Server `.env`
```env
AI_GATEWAY_TOKEN=generate-a-long-random-string
AI_TOOLS_ALLOW_WRITE=false
AI_GATEWAY_RATE_LIMIT=60
```

## Deploy checklist
1. Deploy code.
2. `php artisan migrate` (creates `ai_tool_calls`).
3. Set `AI_GATEWAY_TOKEN` on the server.
4. **MCP:** paste `https://mg-plastic.com/mcp` + Bearer token (see [mcp-setup.md](mcp-setup.md)).
5. **Optional Actions:** import `openapi-admin-ai.yaml` + instructions from `custom-gpt-instructions.md`.
6. Enable writes only when ready: `AI_TOOLS_ALLOW_WRITE=true`.

## Knowledge
Markdown topics live in `docs/ai/knowledge/`. Edit them to improve `faq_search` / `explain_domain` without changing GPT config.
