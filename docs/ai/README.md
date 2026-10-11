# MG Admin AI Gateway

Laravel-hosted tools for ChatGPT Custom GPT Actions and MCP HTTP clients.

## Endpoints
Base: `/api/v1/ai`

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/health` | Health + write flag |
| GET | `/tools` | List tools |
| POST | `/call` | `{ "tool": "...", "arguments": {} }` |
| POST | `/tools/{tool}` | Call tool; body = arguments |
| POST | `/mcp` | JSON-RPC MCP (`tools/list`, `tools/call`) |

Auth header: `Authorization: Bearer <AI_GATEWAY_TOKEN>`

## Server `.env`
```env
AI_GATEWAY_TOKEN=generate-a-long-random-string
AI_TOOLS_ALLOW_WRITE=false
AI_GATEWAY_RATE_LIMIT=60
```

## Deploy checklist
1. Deploy code (GitHub Actions).
2. `php artisan migrate` (creates `ai_tool_calls`).
3. Set `AI_GATEWAY_TOKEN` on the server.
4. Import `docs/ai/openapi-admin-ai.yaml` into a Custom GPT.
5. Paste instructions from `docs/ai/custom-gpt-instructions.md`.
6. Enable writes only when ready: `AI_TOOLS_ALLOW_WRITE=true`.

## Knowledge
Markdown topics live in `docs/ai/knowledge/`. Edit them to improve `faq_search` / `explain_domain` without changing GPT config.
