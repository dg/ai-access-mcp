[![AI Access MCP](https://github.com/user-attachments/assets/57ad39b7-f172-488f-9282-91a0bad3b7be)](https://ai-access.deegee.dev/mcp)

[![Coverage Status](https://coveralls.io/repos/github/dg/ai-access-mcp/badge.svg?branch=master)](https://coveralls.io/github/dg/ai-access-mcp?branch=master)
[![Latest Stable Version](https://poser.pugx.org/ai-access/mcp/v/stable)](https://github.com/dg/ai-access-mcp/releases)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](https://github.com/dg/ai-access-mcp/blob/master/license.md)

 <!---->

<h3>

✅ Lets your AI assistant [hand a task over to Gemini or GPT](#generate_text-ask-another-model)<br>
✅ [Generates images](#generate_image-generate-an-image) into the file you name, with the prompt beside it<br>
✅ Reads files itself, so [long inputs do not pass through the assistant](#files-context-and-the-gemini-cache)<br>
✅ [Never pays twice for one answer](#what-it-guards-you-against) and never overwrites a file<br>
✅ Reports [what every call cost](#cost)

</h3>

 <!---->

**Your AI assistant can ask another model.** Every model is good at something else: Gemini reads a few
hundred pages in one go and rewrites prose so it reads well, GPT draws pictures, and your assistant knows
your code. AI Access MCP is an [MCP server](https://modelcontextprotocol.io) that gives any MCP client,
such as Cursor, Gemini CLI or Claude Code, two tools: `generate_text` asks Gemini or GPT for a text, `generate_image` draws an image with
an OpenAI model. You keep talking to your assistant and it hands over the part another model does better:

> Ask Gemini to rewrite the prose of `docs/install.md` so a beginner can follow it, and save the proposal next to it.

The assistant calls `generate_text` with the path of the file, not its content:

```json
{
	"prompt": "Rewrite the prose of docs/install.md so a beginner can follow it. Keep code blocks and links as they are.",
	"files": ["/var/www/project/docs/install.md"],
	"effort": "high",
	"outputFile": "/var/www/project/docs/install.proposal.md"
}
```

The server reads the file itself, sends it to Gemini and writes the answer to the new file. What comes back
to the assistant is short: the model, the tokens, what the call cost, and in `problem` anything that went
wrong with the answer.

It is built on [AI Access](https://ai-access.deegee.dev), the PHP library that speaks to OpenAI, Claude,
Gemini, DeepSeek and Grok through one interface.

 <!---->

Installation
============

```shell
composer create-project ai-access/mcp ai-access-mcp
```

It needs PHP 8.4 or later with the curl and gd extensions, and an API key of
[Google AI Studio](https://aistudio.google.com/app/apikey), of [OpenAI](https://platform.openai.com/api-keys),
or both.

 <!---->

Configuration
=============

The server is configured by environment variables. The keys are not written into the configuration: each
one is a path to a file holding the key, so the configuration can be shared or committed.

| Variable                | Meaning |
|-------------------------|---------|
| `GEMINI_API_KEY_FILE`   | File with the Gemini key; enables `generate_text` with Gemini models |
| `OPENAI_API_KEY_FILE`   | File with the OpenAI key; enables `generate_image` and `generate_text` with GPT models |
| `AI_ACCESS_READ_ROOTS`  | Directories the tools may read, separated by `;` on Windows and `:` elsewhere |
| `AI_ACCESS_WRITE_ROOTS` | Directories the tools may write; they are readable as well |
| `AI_ACCESS_TEXT_MODEL`  | Default model of `generate_text`, `gemini-3.8-flash` when unset |
| `AI_ACCESS_IMAGE_MODEL` | Default model of `generate_image`, `gpt-image-2` when unset |

A tool appears only when its provider has a key. Nothing may be read or written until you set the roots.

The configuration for Claude Code, in the file `.mcp.json` of a project, or added for all projects with
`claude mcp add --scope user`:

```json
{
	"mcpServers": {
		"ai-access": {
			"command": "php",
			"args": ["/path/to/ai-access-mcp/server.php"],
			"env": {
				"GEMINI_API_KEY_FILE": "/home/me/keys/gemini.txt",
				"OPENAI_API_KEY_FILE": "/home/me/keys/openai.txt",
				"AI_ACCESS_READ_ROOTS": "/home/me/projects",
				"AI_ACCESS_WRITE_ROOTS": "/home/me/projects"
			}
		}
	}
}
```

Other clients take the same block, only somewhere else:

| Client         | Where |
|----------------|-------|
| Claude Desktop | `claude_desktop_config.json` (Windows: `%APPDATA%\Claude\`, macOS: `~/Library/Application Support/Claude/`) |
| Cursor         | `.cursor/mcp.json` in the project, or `~/.cursor/mcp.json` for all projects |
| Gemini CLI     | `.gemini/settings.json` in the project, or `~/.gemini/settings.json` |
| Windsurf       | `~/.codeium/windsurf/mcp_config.json` |

 <!---->

generate_text: Ask Another Model
================================

| Parameter         | Meaning |
|-------------------|---------|
| `prompt`          | The request |
| `instructions`    | The system instruction |
| `model`           | A model id starting with `gemini-` or `gpt-` |
| `effort`          | How hard the model thinks: `none`, `low`, `medium`, `high` |
| `maxOutputTokens` | Limit of the answer, thinking included, 65 536 by default |
| `files`           | Paths of texts, images or PDFs for this call |
| `context`         | Paths of texts shared by a batch of calls |
| `responseSchema`  | JSON Schema the answer must follow |
| `outputFile`      | A new file for the answer, which then does not come back to the assistant |
| `conversation`    | The `conversation` of an earlier result, to continue it |

The result reports the tokens, the cost, and in `problem` anything that went wrong with the answer: a model
that stopped before it finished (`MAX_TOKENS`), an answer that does not match the schema, a file that could
not be written. A text that was paid for is never lost; when the file cannot be written, it comes back in
the result.

A conversation goes on with the `conversation` of the previous result. It keeps the model, the instructions,
the schema and the context, so the next call sends only the new prompt: "these lines are still wrong, fix
them". Conversations live in the memory of the server process.

 <!---->

Files, Context and the Gemini Cache
===================================

Each text file reaches the model in the order you give, under a tag naming the path as you wrote it, so the
prompt can refer to it:

```
<file path="docs/install.md">
...the content, unchanged...
</file>
```

Images and PDFs go as attachments. The content never passes through the assistant, which matters when the
files have hundreds of kilobytes: the assistant's context stays free and you do not pay for the text twice.

`context` is meant for a batch of calls that share a large input, say the finished pages of a manual while
the next one is being rewritten. With Gemini the server stores it together with the instructions in an
explicit cache, and every later call with the same model, instructions and context reads it at a tenth of
the input price. The result says whether the cache was `created` or `reused` and how many tokens came from it.
Another context replaces the cache; when Gemini refuses to cache a content that is too short, the call goes
on without it and the result says so.

 <!---->

generate_image: Generate an Image
=================================

| Parameter         | Meaning |
|-------------------|---------|
| `prompt`          | Complete description of the image |
| `path`            | Absolute path of a new file ending in `.png`, `.jpg` or `.webp`, which also decides the format |
| `referenceImages` | Images to edit or to work from, at most 16 |
| `size`            | `WIDTHxHEIGHT`, for example `1536x1024` |
| `quality`         | `low`, `medium`, `high` or `auto`; the price grows with it |
| `background`      | `transparent`, `opaque` or `auto` |
| `model`           | A model id starting with `gpt-image-` |

Beside the image the server writes the prompt to a `.txt` file of the same name, so you always know what an
image was made from. The result carries a small preview, so the assistant sees what came out.

 <!---->

What It Guards You Against
==========================

A model that hands work over to another one spends real money, and it does so from a conversation that may
contain text from the web or from e-mails. The server is built for that:

- **It asks for consent.** Its instructions and the descriptions of the tools tell the assistant that every
  call costs money and runs only with your explicit consent, and that only you judge the quality of an image.
- **It never pays twice for one answer.** A request that may have reached the provider is not repeated after
  a timeout or a lost connection, nor after a 502 or 504 from a gateway; only a refused one is.
- **It never overwrites a file.** The output name is claimed before the paid call, so two assistants working
  at once never write the same file and a taken name costs nothing.
- **It reads and writes only where you allow.** Outside the roots nothing is touched, and the directory of a
  key file and `.env` files are refused even inside them. Text answers are written only as `.txt`, `.md`,
  `.json` and similar, never as a script.

 <!---->

Cost
====

Every result carries `cost`, the price of the call in USD, and `sessionCost`, the sum since the server
started. The prices come from `prices.neon` in the package, which knows the models in use today and the
dates their prices change; a model missing there reports the cost as `null`. The storage of a Gemini cache
is paid by the hour and the result reports it separately as `storageCostPerHour`.

 <!---->

Limits
======

- Text goes to Gemini and GPT, images to OpenAI. AI Access can talk to more providers, the server does not
  offer them yet.
- Each client runs its own server process and calls the tools one at a time. A generation can take minutes.
- Conversations and the Gemini cache live as long as the process. Deleting the cache when the client ends the
  server is only an attempt; what remains expires within an hour.
- The cost does not include creating a Gemini cache, only reading it.

 <!---->

Credits
-------

AI Access MCP comes from [David Grudl](https://davidgrudl.com), the author of [Nette](https://nette.org),
[Latte](https://latte.nette.org) and [Tracy](https://tracy.nette.org).
