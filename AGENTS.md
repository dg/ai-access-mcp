# To My Agents!

It is my fervent wish that this file guide every AI coding agent working with code in this repository.

It describes an MCP server over the library [AI Access](https://ai-access.deegee.dev): it lets an AI assistant hand work over to other models, text to Gemini or OpenAI and images to OpenAI.

## Commands

- `vendor/bin/tester tests -s` – tests (no real HTTP calls)
- `vendor/bin/phpstan analyse` – static analysis, level 8
- `dresscode check` – coding style (DressCode, `dresscode.neon`)
- after adding a class, run `composer dump-autoload` (classmap autoload)

## Architecture

- `server.php` – entry point: reads the environment (below), registers the tools of the configured providers, instructions for the model, stdio transport.
- `src/ImageTools.php` – thin adapter with `#[McpTool]`: `generate_image`.
- `src/ImageGenerator.php` – the call of the provider, the output files, the preview.
- `src/TextTools.php` – thin adapter with `#[McpTool]`: `generate_text`.
- `src/TextGenerator.php` – picks the provider by the model id, builds the message from files, keeps conversations in the memory of the process, reports what went wrong with the answer.
- `src/ContextCache.php` – the explicit cache of Gemini for the current batch. Gemini refuses a system instruction beside a cache, so the instructions are stored in it. The cost of a call does not include the storage, which the result reports per hour; deleting it when the server ends is only an attempt, the host gives the process 300 ms.
- `src/Sandbox.php` – which files may be read and written.
- `src/ReservedFile.php` – claims an output name before the paid call and writes through a temporary file.
- `src/CostMeter.php` and `prices.neon` – the cost of a call and the sum for the process. **Update the price list when a provider changes its prices or a new model is used**; a dated snapshot id (`model-YYYY-MM-DD`) is priced as the undated model unless it has an entry of its own, and a model missing there reports its cost as unknown.
- `src/McpToolCallGuard.php` – converts everything a tool throws into a `ToolCallException` (a result with `isError`).

**Environment**: `GEMINI_API_KEY_FILE`, `OPENAI_API_KEY_FILE`, `AI_ACCESS_TEXT_MODEL` (default `gemini-3.8-flash`), `AI_ACCESS_IMAGE_MODEL` (default `gpt-image-2`), `AI_ACCESS_READ_ROOTS` and `AI_ACCESS_WRITE_ROOTS` (directories separated by `PATH_SEPARATOR`). The directory of a key file is always denied.

Smoke test over stdio, without any paid call:

```bash
printf '%s\n%s\n' '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"test","version":"1.0"}}}' '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' | OPENAI_API_KEY_FILE=none php server.php
```

## Agreed design

- **Two tools, the model is a parameter**: `generate_text` (Gemini by default, or OpenAI) and `generate_image` (OpenAI). The provider follows from the model id; a model or a parameter the chosen provider does not support is an error, never silently ignored. No embeddings, no batch.
- **A provider is active when its key file is configured** (`GEMINI_API_KEY_FILE`, `OPENAI_API_KEY_FILE`); a tool with no active provider is not registered at all. Keys never appear in the configuration itself.
- **Every client runs its own server process**, so the server keeps no state shared between processes: no archive, no journal, no budget across sessions. The result of a call goes to a path the caller names.
- **Files are read and written only under configured roots**, one list for reading and one for writing; nothing is allowed when a list is unset, and key files and `.env` never are.
- **An output file is never overwritten.** The name is checked and reserved before the paid call, so a collision costs nothing, and the content is written through a temporary file and a rename.
- **A request that may have reached the provider is never repeated**, because the answer would be paid twice. The `RetryClient` of AI Access keeps this rule: any other request than a GET is repeated only after 408, 429, 500, 503 or a connection that was not established.
- **The cost of a call** comes from a price list kept here (AI Access knows no prices) and is reported together with the sum for the server process; there is no budget.
- **A call costs money**, so the instructions of the server and the descriptions of the tools say it runs only with the explicit consent of the user.
