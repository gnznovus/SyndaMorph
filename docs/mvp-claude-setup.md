# SyndaMorph MVP — Claude API plug-in setup

This is **only the source-analysis adapter** for the demo; it does not yet compile a Pattern, run the fidelity loop, or install a client package.

## Tomorrow's setup (local disposable Docker environment)

1. Obtain an **authorized Anthropic API key** and confirm permitted use and billing. A Claude chat seat alone does not guarantee API access.
2. Create a local `.env` at the repository root (already ignored by Git):

   ```dotenv
   ANTHROPIC_API_KEY=YOUR_REAL_KEY_HERE
   SYNDAMORPH_MVP_AI_ENABLED=1
   SYNDAMORPH_CLAUDE_MODEL=claude-sonnet-5-5
   ```

3. Rebuild only the backend: `docker compose up -d --build backend`.
4. The backend listens **inside the Docker network**; the WordPress dashboard does **not yet** expose an AI button. Call the internal endpoint from a trusted development container or temporary local test harness with the existing backend token:

   ```bash
   docker compose exec -T wordpress php -r '
   $payload = json_encode([
       "url" => "https://www.syndacast.com/2026/BKKGOPrototype1/index.html",
       "sectionId" => "section-1",
       "mode" => "browser"
   ]);
   $ch = curl_init("http://backend:3100/mvp/analyze");
   curl_setopt_array($ch, [
       CURLOPT_POST => true,
       CURLOPT_POSTFIELDS => $payload,
       CURLOPT_RETURNTRANSFER => true,
       CURLOPT_HTTPHEADER => [
           "Content-Type: application/json",
           "X-SyndaMorph-Token: local-poc-token-change-before-deployment"
       ],
       CURLOPT_TIMEOUT => 100
   ]);
   $result = curl_exec($ch);
   $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
   curl_close($ch);
   echo "HTTP " . $status . "\n" . $result . "\n";
   '
   ```

   If the image lacks the PHP cURL extension, use a PHP `file_get_contents` HTTP stream or a Node test harness in a trusted container instead.

5. Expected response: source section metadata, a schema-validated `plan`, model identifier, usage counters, and warnings clearly noting **mobile is not yet captured**.

## Constraints and next milestone

- This is deliberately disabled by default (`SYNDAMORPH_MVP_AI_ENABLED=0`).
- The key exists only in the backend container environment. Never send the key to WordPress, the UI, tickets, Git, or chat.
- A malformed or interrupted response is rejected; model replies cannot provide executable code through this contract.
- Input evidence minimizes HTML to structural tags and text; this prototype is **not a PII sanitization guarantee**. Test only with approved public fixtures, not client-private or authenticated pages.
- Provider requests are made one per analyze call. No hidden loops, no background spend.
- `sectionId` must refer to a real section returned by capture. Initial Hotel Indigo test may use `section-1`.
- Next development step: mobile capture and live Gutenberg compiler/validator adapters, then API-backed repair iterations.

## WordPress AI Production Team POC (later milestone)

This document describes the historical backend MVP setup. The current `feat/secure-gateway-leasing-v1` branch additionally implements a **real Anthropic four-agent POC inside WordPress Operations**. It is separate from the backend scaffold described above.

To run it, configure a paid model under **Tools → SyndaMorph → AI Integration**, then open **AI Production Team → Setup**, enter a standalone brief under **Task instructions** (no URL/HTML/ZIP required) and choose **Run real AI team** between **Start simulation** and **Reset**. The job returns Boss, Designer, Developer and Validator reports with per-role token/duration/cost measurements. No code is executed, no Gutenberg pattern is created, and nothing is published automatically.

See [Real AI Production Team — POC #1](real-ai-team-poc-1.md) for verified baseline results, safety boundaries and current limitations.
