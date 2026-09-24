# Mago Assistant addon: MCP servers as a skill

[Model Context Protocol](https://modelcontextprotocol.io) (MCP) is a standard way for a tool server to
tell an AI client which tools it has and how to call them. This addon discovers every MCP server
already installed in your Magento project and exposes their tools to Mago Assistant as one skill,
`mcp`. Install [Bricklayer](https://github.com/inchoo/magento-bricklayer) and the first thing you get
for free is catalog, order and system tools you can ask the assistant about in plain language.

## Install

```bash
composer require mago-assistant/magento2-mcp
bin/magento module:enable MagoAssistant_Mcp
bin/magento setup:upgrade
bin/magento cache:flush
```

Or drop the module into `app/code/MagoAssistant/Mcp` and run the same `module:enable`,
`setup:upgrade`, `cache:flush` sequence.

## Enable a server

Discovery never enables anything by itself — it only makes a server visible so an administrator can
turn it on.

In the admin: **Stores > Admin Assistant > MCP Servers**. Discovered and manually added servers are
listed there; open one to enable it, review its tools, and set a read/write/disabled override per
tool.

From the CLI:

```bash
bin/magento mago:mcp:discover        # scan Composer packages and, if enabled, .mcp.json
bin/magento mago:mcp:list            # show every known server, its source and its state
bin/magento mago:mcp:list <server>   # show one server's tools and their mode
bin/magento mago:mcp:enable <server>
bin/magento mago:mcp:disable <server>
bin/magento mago:mcp:refresh <server>  # drop the cached tool list and re-fetch it
```

## How it reaches the model

Every enabled server's tools are folded into a single Mago skill named `mcp`. The model does not see
one tool per MCP tool; it sees one skill whose `action` parameter is `server:tool` (for example
`bricklayer:product-list`). The full per-action argument schema is not part of the always-sent
description — it arrives in the just-in-time instructions after the first call to that action, and
again in the error message if the call's arguments do not validate. Each call spawns one server
process, makes the one request, and lets the process exit; there are no long-lived MCP sessions.

Tool lists are fetched once per server and cached in the `mago_mcp` cache type for the configured
lifetime (an hour by default). `setup:upgrade` registers and enables that cache type automatically; if
it is ever disabled, `bin/magento cache:enable mago_mcp` turns it back on, and
`bin/magento cache:clean mago_mcp` (or `mago:mcp:refresh`) forces a re-fetch after a server's tools
change.

Every enabled tool adds a line to the skill's description on every request to the model, whether or
not that tool is used. Enabling a large server measurably grows the token cost of every conversation
turn — enable only the servers and tools you actually want the assistant reaching for.

## Read, write, disabled

Each tool is classified `read`, `write`, or `disabled`. The default classification comes from the
tool's name, split on `-`/`_`:

- A **write word** anywhere in the name (`create`, `update`, `delete`, `set`, `add`, `assign`,
  `cancel`, `hold`, `unhold`, `execute`, `run`, `runner`, `generate`, `reinitialize`, `flush`, `clean`,
  `write`, `remove`, `import`, `sync`, `save`, `put`, `post`, `send`, `apply`, `upgrade`, `install`,
  `clear`, `reindex`, `regenerate`, `rebuild`, `reset`, `purge`, `truncate`, `drop`) always wins, no
  matter where else a read word appears.
- Otherwise the name is **read** if its first or last segment is a read verb (`get`, `list`, `inspect`,
  `search`, `show`, `read`, `find`, `help`, `validate`, `diagnose`, `check`), or its last segment is a
  read noun (`tree`, `info`, `status`, `context`, `schema`, `endpoints`, `comments`, `items`, `orders`,
  `addresses`, `rewrites`, `log`, `structure`, `types`, `attributes`, `products`, `configuration`).
- Anything else — including a name with no recognisable word at all — **fails closed to write**.
  `query` is deliberately not on the read list: a query can mutate. (Bricklayer's own `database-query`
  tool only runs `SELECT` statements; its shipped default below overrides this classifier's write with
  a read, rather than changing the general rule.)

A server's own annotations (`readOnlyHint`, `destructiveHint`) are read too, but only to make a tool
*stricter* than the name would suggest: `readOnlyHint: false` or `destructiveHint: true` can turn a
name-classified read into a write, never the other way around. Servers are not required to annotate
correctly, and a wrong "this is safe" annotation must never be trusted over the name.

The administrator can override any tool's mode to `read`, `write`, or `disabled` on the server's edit
page; that override always wins over both the name and the annotations.

A `write` action goes through Mago's normal confirmation card before it runs; a `delete` or `cancel`
style action is flagged irreversible and the card shows its impacts. An invalid write — unknown
action, malformed JSON arguments, a missing required argument, or an argument of the wrong scalar type
— is refused before the confirmation card is even shown, so the administrator is never asked to
confirm a call that would fail anyway.

## Shipped Bricklayer defaults

Because Bricklayer is a developer tool with tools that touch the filesystem and the codebase, this
addon ships its execution surfaces disabled by default:

- `code-runner`, `code-runner-help`, `batch-execute`, `reinitialize`,
  `generate-module`, `generate-model`, `generate-controller`, `generate-api`

**These run arbitrary PHP. Enabling any of them turns the chat panel into a remote shell for anyone who
holds the chat permission. Only enable them for a trusted, access-controlled admin, and never on a
production store.**

`database-query` ships as a `read`: Bricklayer only accepts `SELECT` statements from it and caps the
number of rows returned, so it does not belong with the execution surfaces above.

Every tool that returns personal data by design — `customer-*`, `order-*`, `invoice-*`, `shipment-*`,
`creditmemo-*` — is flagged `personal data` in the admin, but otherwise follows the normal read/write
rule and ships exposed like any other tool: reads (`customer-get`, `order-list`, ...) are available to
the model immediately, writes (`customer-create`, `order-cancel`, ...) still go through the
confirmation card.

## Privacy

An MCP tool's result comes back as one text field, declared `PUBLIC` in the field classification,
because the module cannot know which parts of arbitrary server output are personal data. Mago's own
heuristic scrub still runs over that text before it reaches the model, but it is a heuristic: it catches
emails, phone numbers and similar values, but names and street addresses embedded in a result are not
reliably caught.

The owner's accepted trade-off for this build is that Bricklayer's personal-data read tools
(`customer-get`, `order-list`, and the rest) ship exposed rather than disabled, so the assistant can
answer ordinary customer-service questions without a manual step first. That means those tools can
return customer names and addresses to the model. If that is not acceptable for your store, set the
individual tool's mode to `disabled` on the server's edit page — the personal-data flag on each row
makes them easy to find — or disable the whole server.

## For package authors

A Composer package can declare its MCP server explicitly under `extra.mago-mcp` in its `composer.json`:

```json
{
    "extra": {
        "mago-mcp": {
            "name": "my-server",
            "command": ["php", "bin/my-mcp-server"],
            "env": {"MY_SERVER_MODE": "readonly"},
            "cwd": "vendor/vendor-name/my-package"
        }
    }
}
```

`name` and `cwd` are optional; `command` is required and is run as given, one array entry per
argv entry (no shell parsing). `env` is optional and its values are passed to the process unchanged.

Without an `extra.mago-mcp` block, a package is still discovered if it ships a `bin` entry whose
basename contains `mcp` and does not end in `-docker` (a `-docker` binary is a wrapper meant to be run
on the host, not inside this container, so it is skipped). That binary is run as
`php vendor/bin/<basename>`.

Either way, the discovered name is normalised — lower-cased, non `[a-z0-9_-]` characters collapsed to
`-`, then a leading `magento-`, `magento2-`, or `module-` prefix and a trailing `-mcp-server` or `-mcp`
suffix are stripped. This is what lets a Composer package and a `.mcp.json` entry for the same server
resolve to one row; when both name the same server, the Composer declaration wins.

### Shipping defaults for your server

`Service\Catalog\DefaultOverrides` ships no server names of its own; it reads whatever profiles are
wired into it via `di.xml`. A module can add a profile for its own server the same way this addon adds
one for Bricklayer, without editing any file in this module:

```xml
<type name="MagoAssistant\Mcp\Service\Catalog\DefaultOverrides">
    <arguments>
        <argument name="disabledTools" xsi:type="array">
            <item name="acme" xsi:type="array">
                <item name="wipe-database" xsi:type="string">wipe-database</item>
            </item>
        </argument>
        <argument name="readTools" xsi:type="array">
            <item name="acme" xsi:type="array">
                <item name="search" xsi:type="string">search</item>
            </item>
        </argument>
        <argument name="personalDataPrefixes" xsi:type="array">
            <item name="acme" xsi:type="array">
                <item name="customer" xsi:type="string">customer-</item>
            </item>
        </argument>
    </arguments>
</type>
```

The key under each argument is the normalised server name (see above); the value is a list of tool
names (`disabledTools`, `readTools`) or tool-name prefixes (`personalDataPrefixes`). `disabledTools`
ships tools disabled until an administrator switches them on; `readTools` forces tools the name
classifier would otherwise call a write to read instead; `personalDataPrefixes` flags tool names
returning personal data regardless of their mode. Magento merges array arguments by item name, so this
block can sit in the module's own `di.xml` alongside the entries for any other server without touching
this addon.

## `.mcp.json`

When "Also discover from `.mcp.json`" is enabled, stdio entries in the Magento root's `.mcp.json` (the
file Claude Code and Cursor read) are discovered too. A `docker exec ... <container> <command>` entry
is unwrapped into the inner command, keeping `-w` as the process's working directory and `-e KEY=VALUE`
pairs as environment, since that command is what actually needs to run once the module spawns it. An
HTTP or SSE entry (anything with a `url` instead of a `command`) is skipped — this module only speaks
MCP over stdio, and there is no host-side executable to run for a remote server.

## Logging

A failed process spawn, a malformed handshake, or a tool call that raises an MCP error is written to
`var/log/mago-error.log`. When Mago's own debug flag is on, every call also writes metadata —
the action, argument keys, whether the result was an error, and the result's block count and character
length — to `var/log/mago-debug.log`. Raw tool arguments and raw result text are never written to the
debug log, only that metadata, in keeping with Mago's rule of keeping potentially sensitive data out of
its own logs even in debug mode.

## Limits of version 1

- **stdio only.** HTTP and SSE MCP servers are not supported; see `.mcp.json` above.
- **Tools only.** MCP resources and prompts are not exposed as anything the model can use.
- **No persistent processes.** Every call spawns a fresh process and lets it exit; a server that
  expects a long-lived session (for example to hold state between calls) will not behave as its own
  documentation might suggest.
- **No widgets.** Only a tool's text content is shown; image and other non-text content blocks are
  reported as present but not rendered.
- **One permission grant for all servers.** The admin ACL resource that gates read and write calls
  (`MagoAssistant_Mcp::use` / `MagoAssistant_Mcp::use_write`) is not split per server, so an admin user
  who can call one enabled server's read tools can call every enabled server's read tools.
- **Protocol versions 2024-11-05 through 2025-06-18** are negotiated; a server that only speaks an
  older or newer version is rejected during the handshake.

## Environment variables

Whatever environment variables are stored for a server — from a Composer `extra.mago-mcp` block, a
`.mcp.json` entry, or typed into the manual "Add server" form — are passed to that server's process
exactly as stored, with no filtering. This addon does not attempt to restrict which variables can be
set: whoever can set a server's environment can already set its executable and arguments, so filtering
the environment would not add any protection. Treat the ability to add or edit an MCP server the same
as the ability to run arbitrary code on the Magento host, and restrict that admin resource
accordingly.

## Development

From the Magento root:

```bash
vendor/bin/phpunit -c app/code/MagoAssistant/Mcp/phpunit.xml.dist app/code/MagoAssistant/Mcp/Test/Unit
```

or, from inside the module directory:

```bash
vendor/bin/phpunit
```

Lint:

```bash
vendor/bin/phpcs --standard=phpcs.xml .
```

The stdio client tests run a real fake MCP server over a real process — `Test/Unit/Fakes/fake-mcp-server.php`,
a small self-contained PHP script started with `exec`/`proc_open` — so they exercise the actual
handshake, timeouts, and stderr handling rather than a mock transport. That fixture is excluded from
the module's own strict-types and file-header rules and is the reason `phpcs.xml` allows `exec`-family
calls under `Test/`; production code stays fully covered by the standard sniffs.
