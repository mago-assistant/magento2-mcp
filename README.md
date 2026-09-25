# Mago Assistant addon: MCP servers as skills

[Model Context Protocol](https://modelcontextprotocol.io) (MCP) is a standard way for a tool server to
tell an AI client which tools it has and how to call them. This addon discovers every MCP server
already installed in your Magento project and turns each of its tools into its own Mago skill, listed
beside Mago's own. Install a server such as [Bricklayer](https://github.com/inchoo/magento-bricklayer)
and the first thing you get for free is catalog, order and system tools you can ask the assistant about
in plain language.

## Install

```bash
composer require mago-assistant/magento2-mcp
bin/magento module:enable MagoAssistant_Mcp
bin/magento setup:upgrade
bin/magento cache:flush
```

Or drop the module into `app/code/MagoAssistant/Mcp` and run the same `module:enable`,
`setup:upgrade`, `cache:flush` sequence.

### Upgrading from 1.0.0

Version 1.0.0 exposed every write-named tool behind the confirmation card, shipped a profile that made
one server's query tool a read, and let an administrator override any tool's mode to `read`, `write` or
`disabled` on a per-tool row. All three are gone. `setup:upgrade` drops the `tool_overrides` column
outright — any per-tool overrides or disabled tools from an earlier version are discarded — and a
tool's type is now fixed by its name and the server's annotations alone, exactly as Mago's own skills
carry their type in code. There is no mode select anywhere and none is needed: every tool of an enabled
server runs, a read at once and a write behind Mago's confirmation card.

The single `mcp` skill is also gone, replaced by one skill per tool (`<server>__<tool>`). Its per-user
permission rows do not apply to the new per-tool skills: a user you had set to Read Only or Disabled on
`mcp` falls back to the role ACL after the upgrade and regains every read tool the role allows and, for
a write-capable role, every write tool too. Before upgrading, remove `MCP Tools - Read` /
`MCP Tools - Write` from roles that should not use MCP, or set per-user permissions on the new per-tool
rows afterwards. The addon's own ACL resources (`MCP Tools - Read` / `MCP Tools - Write`) now also decide
which MCP skills a role is offered at all, not only which may run.

An execution tool — a code runner, a raw SQL query tool — is now simply a write tool like any other: it
runs behind the confirmation card for any admin whose role holds `MCP Tools - Write`, with no separate
switch to keep it off. See Security below for how to restrict it.

The "MCP Servers" menu entry is under **Stores > Admin Assistant > MCP Servers** for discovery,
enable/disable, rescan and refresh; there is no edit page and no manual-server form any more — add a
server discovery does not find as a stdio entry in `.mcp.json` instead (see below).

## Enable a server

Discovery never enables anything by itself — it only makes a server visible so an administrator can
turn it on.

In the admin: **Stores > Admin Assistant > MCP Servers** is a grid of discovered servers — name, source,
command, enabled, tool count, status — with Enable/Disable per row and Rescan and Refresh tool lists as
toolbar buttons. There is no edit page and no "Add manual server" form: a server discovery does not find
is added as a stdio entry in the Magento root's `.mcp.json` (see below), then picked up the next time the
grid rescans.

Once a server is enabled, each of its tools appears as its own row, `<server>__<tool>`, in **Stores >
Admin Assistant > Skills & Permissions** beside Mago's own skills, with Mago's own per-user permission
controls and nothing added by this addon.

From the CLI:

```bash
bin/magento mago:mcp:discover        # scan Composer packages and, if enabled, .mcp.json
bin/magento mago:mcp:list            # show every known server, its source and its state
bin/magento mago:mcp:list <server>   # show one server's tools and their type
bin/magento mago:mcp:enable <server>
bin/magento mago:mcp:disable <server>
bin/magento mago:mcp:refresh <server>  # drop the cached tool list and re-fetch it
```

## How it reaches the model

Every tool of an enabled server is its own Mago skill, named `<server>__<tool>` (two underscores,
because AI providers only allow letters, digits, `_` and `-` in a tool name, so a colon cannot separate
the parts). The model receives one function definition per exposed tool on every turn, each with the
tool's own argument schema. The description sent every turn is the tool's first sentence, in the form
`<server>: <tool> — <summary>`, followed by ` [personal data]` and/or ` [runs code, SQL or commands]`
when the tool's name says so; the full description and full schema arrive as just-in-time instructions
after the first call to that tool, and again in the error message if the call's arguments do not
validate. Each call spawns one server process, makes the one request, and lets the process exit; there
are no long-lived MCP sessions.

Each tool an admin's role and per-user permission allow adds one function definition to every request to
the model on their behalf, whether or not that tool is used in that turn. Enabling a large server
measurably grows the token cost of every conversation turn for every admin who can call it; disable a
server you are not using, or hold `MCP Tools - Read` / `MCP Tools - Write` on fewer roles, to keep that
cost down.

Tool lists are fetched once per server and cached in the `mago_mcp` cache type for the configured
lifetime (an hour by default). `setup:upgrade` registers and enables that cache type automatically; if
it is ever disabled, `bin/magento cache:enable mago_mcp` turns it back on, and
`bin/magento cache:clean mago_mcp` (or `mago:mcp:refresh`) forces a re-fetch after a server's tools
change.

While a call runs, the chat panel reads like one of Mago's own skills, because it is one: the tag is
the tool's own skill name (`shop__order-get`), and the status line a plain phrase built from the
tool name, `Getting order...`. Two `before` plugins on Mago's streaming entry points build that phrase
for the status line only — no tag relabelling is needed, and there is no `around` plugin anywhere in
the module.

## Read or write

Each tool is classified `read` or `write`. The classification comes from the tool's name, split on
`-`/`_`:

- A **write word** anywhere in the name (`create`, `update`, `delete`, `set`, `add`, `assign`,
  `cancel`, `hold`, `unhold`, `execute`, `run`, `runner`, `generate`, `reinitialize`, `flush`, `clean`,
  `write`, `remove`, `import`, `sync`, `save`, `put`, `post`, `send`, `apply`, `upgrade`, `install`,
  `clear`, `reindex`, `regenerate`, `rebuild`, `reset`, `purge`, `truncate`, `drop`, `change`, `mark`,
  `replace`, `export`, `enable`, `disable`, `toggle`, `activate`, `deactivate`, `unlock`, `merge`,
  `push`, `commit`, `refund`, `void`, `approve`, `publish`, `archive`, `trigger`, `kill`, `restart`,
  `start`, `stop`, `move`, `edit`, `upload`, `fork`, `dismiss`, `charge`, `ship`, `finalize`,
  `moderate`, `cleanup`, `fill`, `click`, `press`, `drag`, `close`, `fix`, `repair`, `patch`,
  `capture`, `rename`, `erase`, `wipe`, `destroy`, `revoke`, `unassign`, `unlink`, `detach`,
  `invalidate`, `expire`, `terminate`, `suspend`, `ban`, `reject`, `revert`, `rollback`, `restore`,
  `prune`) always wins, no matter where else a read word appears.
- Otherwise the name is **read** if its first or last segment is a read verb (`get`, `list`, `inspect`,
  `search`, `show`, `read`, `find`, `help`, `validate`, `diagnose`, `check`), or its last segment is a
  read noun (`tree`, `info`, `status`, `context`, `schema`, `endpoints`, `comments`, `items`, `orders`,
  `addresses`, `rewrites`, `log`, `structure`, `types`, `attributes`, `products`, `configuration`).
- Anything else — including a name with no recognisable word at all — **fails closed to write**.
  `query` is deliberately not on the read list: a query can mutate. A SELECT-only query tool
  therefore classifies `write`, so it runs behind Mago's confirmation card rather than skipping it.

A server's own annotations (`readOnlyHint`, `destructiveHint`) are read too, but only to make a tool
*stricter* than the name would suggest: `readOnlyHint: false` or `destructiveHint: true` can turn a
name-classified read into a write, never the other way around. Servers are not required to annotate
correctly, and a wrong "this is safe" annotation must never be trusted over the name.

A tool's type is then fixed, exactly like one of Mago's own skills carrying its type in code: there is
no override, no `disabled` mode, and no hidden state. Who may use a tool is Mago's business alone — the
role ACL (`MCP Tools - Read` / `MCP Tools - Write`) and the per-user table on that tool's own Skills &
Permissions page, the same as for any other skill. See Security below for how to restrict a tool you
would rather keep off.

A `write` tool goes through Mago's normal confirmation card before it runs; a `delete` or `cancel`
style tool is flagged irreversible and the card shows its impacts. An invalid write — a missing required
argument, or an argument of the wrong scalar type — is refused before the confirmation card is even
shown, so the administrator is never asked to confirm a call that would fail anyway. A tool a user's role or per-user permission does not allow is simply not offered to the
model as a skill for that user, exactly like a disallowed Mago skill; the role must also hold the
addon's own resource for that tool's type (`MCP Tools - Read` or `MCP Tools - Write`).

Names can fool a word list: a write disguised behind a read noun (`nuke-log`) still classifies read.
Review a new server's tools once; since there is no per-tool override to correct a wrong name locally,
restrict a mis-named tool through the role, the per-user setting, or by disabling the server, until
upstream fixes its name.

A tool whose name says it runs code, SQL or commands (`exec`, `execute`, `eval`, `evaluate`, `run`,
`runner`, `script`, `shell`, `bash`, `terminal`, `console`, `cli`, `command`, `php`, `sql`, `unsafe`,
`generate`, `scaffold`, `reinitialize`, `install`, `uninstall`, `upgrade`, `migrate`, `batch`) carries
` [runs code, SQL or commands]` on its description and `execution` in `mago:mcp:list`; such a tool runs
arbitrary PHP or SQL — see Security below. Tools that return personal data by their name (`customer`,
`order`, `invoice`, `shipment`, `creditmemo`, `address`, `email`, `phone`, `account`, `user`,
`subscriber`, `quote`, `cart`, `wishlist`, `review`) carry ` [personal data]` the same way. Both flags
are information; neither changes a tool's type.

## Privacy

An MCP tool's result comes back as one text field, declared `PUBLIC` in the field classification,
because the module cannot know which parts of arbitrary server output are personal data. Mago's own
heuristic scrub still runs over that text before it reaches the model, but it is a heuristic: it catches
emails, phone numbers and similar values, but names and street addresses embedded in a result are not
reliably caught.

Read tools that return personal data (`customer-get`, `order-list` and the like) run for every user
whose role and per-user permission allow them, so the assistant can answer ordinary customer-service
questions without a manual step first. That means those tools can return customer names and addresses
to the model. If that is not acceptable for your store, set the individual tool to Disabled for the
affected users on its own Skills & Permissions page — the `[personal data]` flag on each tool's
description makes them easy to find — restrict the `MCP Tools - Read` role, or disable the whole server.

## Security

An execution tool — a code runner, a raw SQL query tool — is a write skill like any other MCP tool:
available, behind the confirmation card, to every admin whose role holds `MCP Tools - Write`. The addon
does not gate it any more tightly than that. The trade-off is deliberate: one confirmation click stands
between that permission and arbitrary PHP or SQL running on the store, in exchange for MCP tools
behaving exactly like Mago's own skills, with no extra layer of switches to reason about.

A raw SQL tool whose name only says `query` carries no execution flag (`query` is deliberately not an
execution word); it is still a write behind a confirmation card.

Restrict it the same way any of Mago's own write skills is restricted:

- Grant `MCP Tools - Write` to few roles.
- Set the tool to Disabled for the users who should not have it, on that tool's own Skills &
  Permissions page.
- Disable the server entirely, on the MCP Servers page.

The ` [personal data]` and ` [runs code, SQL or commands]` flags on a tool's description, and the
matching suffixes in `mago:mcp:list`, make it easy to find which tools need this.

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

A tool's type is not something a package ships: it comes from the tool's name and, when present, the
server's own MCP annotations. If your server marks tools with annotations, `readOnlyHint: false` and
`destructiveHint: true` are honoured — they only ever make a tool stricter; `readOnlyHint: true` alone
is not trusted.

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
the skill, argument keys, whether the result was an error, and the result's block count and character
length — to `var/log/mago-debug.log`. Raw tool arguments and raw result text are never written to the
debug log, only that metadata, in keeping with Mago's rule of keeping potentially sensitive data out of
its own logs even in debug mode.

## Limits of version 2

- **The registry plugin depends on Mago's `ToolRegistry`, not `@api`.** Every MCP skill reaches the
  Skills & Permissions list, the chat, and the skill edit page through `after` plugins on that class's
  public methods. If Mago renames or removes them, the MCP skills disappear from the list and from
  chat until the plugin is updated to match; Mago itself keeps working throughout.
- **Tool arguments whose name starts with `_` are dropped** before the call: Mago reserves that prefix
  for its own keys (`_admin_user_id`).
- **A user's per-skill permission rows are now per tool, so there can be many** — one row per exposed
  MCP tool instead of one row for the whole `mcp` skill. Mago's own Skills & Permissions grid pages, so
  this is a longer list to page through, not a broken one.
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
- **The status-line phrase depends on Mago internals.** `ChatService` is not `@api`, so the two
  `before` plugins read its `tool_status` event shape (`name`/`status`/`message`) without a contract.
  If Mago changes that shape, the phrase-building simply does not match and the status line falls back
  to Mago's own generic message; the tag, which is just the skill's own name and needs no relabeling,
  is unaffected either way.

## Environment variables

Whatever environment variables are stored for a server — from a Composer `extra.mago-mcp` block or a
`.mcp.json` entry — are passed to that server's process exactly as stored, with no filtering. This
addon does not attempt to restrict which variables can be set: whoever can edit a package's
`composer.json` or the Magento root's `.mcp.json` can already set that server's executable and
arguments, so filtering the environment would not add any protection. Treat the ability to edit either
file the same as the ability to run arbitrary code on the Magento host.

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
