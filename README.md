# Mago Assistant addon: MCP servers as skills

[Model Context Protocol](https://modelcontextprotocol.io) (MCP) is a standard way for a tool server to
tell an AI client which tools it has and how to call them. This addon exposes every tool of every
enabled MCP server as its own [Mago](https://github.com/mago-assistant/mago) skill, listed beside Mago's
own, over either of two transports:

- **stdio**: a server that runs as a local process, discovered automatically from Composer packages
  and from the Magento root's `.mcp.json`. Install a server such as
  [Bricklayer](https://github.com/inchoo/magento-bricklayer) and its catalog, order and system tools
  are skills you can ask the assistant about in plain language.
- **Streamable HTTP**: a remote server reached by URL, with no authentication or a bearer token, added
  through `.mcp.json` or shipped by any module as a definition.

The module is generic: it knows no vendor server. Which servers exist is entirely a matter of what is
installed, what `.mcp.json` says, and which definitions modules register.

**Status:** version 1.0.0 is under development on branch `feature/mcp-http-and-stdio` (`composer.json`
still says 2.0.0 until the release step changes it). Per-admin OAuth for remote servers and an admin form
for adding a remote server by hand are the next two steps; see `MERGE.md` for how this branch came to be
and what is still to come.

## Install

```bash
composer require mago-assistant/magento2-mcp
bin/magento module:enable MagoAssistant_Mcp
bin/magento setup:upgrade
bin/magento cache:flush
```

Or drop the module into `app/code/MagoAssistant/Mcp` and run the same `module:enable`,
`setup:upgrade`, `cache:flush` sequence. `setup:upgrade` also enables the addon's `mago_mcp` cache
type, renames rows an earlier development version stored under a differently normalised name (deleting a
duplicate and keeping the enabled one), runs a discovery scan, and drops the cached tool list of every
row that scan inserted or updated.

## Configuration

**Stores > Configuration > Mago Assistant > MCP Servers**:

| Setting | Default | What it does |
|---|---|---|
| Enabled | Yes | Turns every MCP skill on or off at once |
| Also discover from `.mcp.json` | Yes | Reads the Magento root's `.mcp.json` besides Composer packages and module definitions |
| Call timeout (seconds) | 60 | How long one tool call may take, over stdio or HTTP; a server row may set its own |
| Tool list cache lifetime (seconds) | 3600 | How long a fetched tool list is kept in the `mago_mcp` cache type |
| Maximum result characters | 16000 | Longer text results are truncated before they reach the model |

## Servers, where they come from

Every server is one row in `mago_mcp_server`, with a `source` that says who put it there and decides
what an administrator may change:

| source | transport | created by | editable |
|---|---|---|---|
| `composer` | stdio | a Composer package with an `extra.mago-mcp` block or an `*mcp*` binary | enable, disable |
| `mcp_json` | stdio or http | the Magento root's `.mcp.json` (when enabled in config) | enable, disable |
| `module` | stdio or http | a `ServerDefinition` any module registers in `di.xml` | enable, disable |
| `manual` | http | an administrator (the Add form is not built yet) | everything |

Sources run in the order module, Composer, `.mcp.json`. The first source to yield a name wins; a later
copy of the same name is logged and dropped. A `manual` row is never touched by a scan.

Discovery never enables anything by itself. It only makes a server visible so an administrator can
turn it on. A rescan re-applies every discovered row's fields from its source, keeping only `enabled`
(and a stored bearer token when the source supplies none). A server a source no longer yields, a
removed package, a disabled module, `.mcp.json` scanning switched off, is flagged Missing in the grid
and in `mago:mcp:discover`, but stays enabled until you disable it.

Names are normalised: lower-cased; every run of characters other than `a-z0-9_` becomes one `_`;
leading and trailing `_` are trimmed; then a leading `magento2_`, `magento_` and `module_` and a
trailing `_mcp_server` or `_mcp` are removed, each only if something remains. A package
`acme/magento-widget` and a `.mcp.json` entry "Magento Widget" both become `widget`: one row. `a-b` and
`a_b` are therefore the same server.

## Enable a server

In the admin: **Stores > Admin Assistant > MCP Servers** is a grid of known servers with Enable and
Disable per row and Rescan and Refresh tool lists as toolbar buttons.

Once a server is enabled, each of its tools appears as its own row, `mcp_<server>__<tool>`, in
**Stores > Admin Assistant > Skills & Permissions** beside Mago's own skills, with Mago's own per-user
permission controls.

From the CLI:

```bash
bin/magento mago:mcp:discover          # run every source; new servers are added disabled
bin/magento mago:mcp:list              # every server: source, transport, auth, state, command or URL
bin/magento mago:mcp:list <server>     # one server's tools, even if disabled: type, where it came from, flags
bin/magento mago:mcp:enable <server>
bin/magento mago:mcp:disable <server>
bin/magento mago:mcp:refresh [<server>]  # drop the cached tool list (all servers if none given); fetched again on next use
```

## Remote servers over HTTP

A remote server speaks MCP's Streamable HTTP transport (specification 2025-06-18): one endpoint,
JSON or SSE-formatted responses, a session id the server may hand out. Authentication is `none` or
`bearer`, a static token shared by every admin; OAuth per admin is the next step and until then a row
with `auth_type = oauth` exposes no tools and writes "needs an OAuth connection" to
`var/log/mago-error.log` on every request that lists tools (it is neither cached nor shown in the grid).

### From `.mcp.json`

When "Also discover from `.mcp.json`" is enabled, an entry with a `url` and `type` absent, `http` or
`streamable-http` becomes an http row:

```json
{
    "mcpServers": {
        "analytics": {
            "type": "http",
            "url": "https://mcp.example.com/mcp",
            "headers": { "Authorization": "Bearer <token>" }
        }
    }
}
```

An `Authorization: Bearer` header becomes the row's bearer token, stored encrypted; any other header is
ignored and named in the log. A `type: sse` entry is the older two-endpoint transport this module does
not speak; it is skipped and logged.

### From a module definition

Any module can ship a server as pure configuration. Register a `ServerDefinition` as an item of the
definition registry in the module's `di.xml`:

```xml
<type name="MagoAssistant\Mcp\Service\Discovery\DefinitionRegistry">
    <arguments>
        <argument name="definitions" xsi:type="array">
            <item name="analytics" xsi:type="object">Vendor\Module\Mcp\AnalyticsServer</item>
        </argument>
    </arguments>
</type>
<virtualType name="Vendor\Module\Mcp\AnalyticsServer" type="MagoAssistant\Mcp\Service\Discovery\ServerDefinition">
    <arguments>
        <argument name="name" xsi:type="string">analytics</argument>
        <argument name="label" xsi:type="string">Analytics</argument>
        <argument name="transport" xsi:type="string">http</argument>
        <argument name="url" xsi:type="string">https://mcp.example.com/mcp</argument>
        <argument name="authType" xsi:type="string">bearer</argument>
        <argument name="bearerToken" xsi:type="string">…</argument>
        <argument name="timeout" xsi:type="number">30</argument>
        <argument name="outputPublic" xsi:type="boolean">true</argument>
        <argument name="allowedTools" xsi:type="array">
            <item name="0" xsi:type="string">query-metrics</item>
            <item name="1" xsi:type="string">list-domains</item>
            <item name="2" xsi:type="string">who-am-i</item>
        </argument>
        <argument name="fieldClassificationOverrides" xsi:type="array">
            <item name="who-am-i" xsi:type="array">
                <item name="*" xsi:type="array"><item name="0" xsi:type="string">public</item></item>
                <item name="email" xsi:type="array"><item name="0" xsi:type="string">strip</item></item>
            </item>
        </argument>
        <argument name="errorHints" xsi:type="array">
            <item name="does not exist" xsi:type="string">List the domains first and retry with one of them.</item>
        </argument>
    </arguments>
</virtualType>
```

Put this in the module's global `etc/di.xml`. `name` is required and is normalised like every other
name; everything else has a default, and `transport` defaults to `http`. A stdio definition sets
`transport` to `stdio` and gives `command` (an array), `env` and `cwd` instead of `url` and `authType`.
When two modules register the same name, the last registered definition wins. The storable fields
(label, transport, URL, auth, allowed tools, timeout, output public, replaces skill) become the row; on
every rescan the definition wins over the row for all of them except `enabled`, so a module update takes
effect without an admin action. The per-tool field classification and the error hints are never stored:
the catalog reads them from the definition each time. `replacesSkill` is stored but has no effect until
the OAuth step.

A token given in `di.xml` stays plain text in that file and in `generated/` metadata; only the database
copy is encrypted. This module registers no definition itself. The registry's default is empty.

### Allowed tools

`allowedTools` is a token-cost filter, not a permission: every exposed tool's definition is sent to the
model on every turn, so a server with sixty tools you use three of is worth trimming. A name not on the
list is simply not a skill. Who may call a listed tool is still Mago's ACL and per-user table.

## How a tool reaches the model

Every tool of an enabled server is one Mago skill named `mcp_<server>__<tool>`: lower-case,
`[a-z0-9_]` only, at most 64 characters (a longer name is cut and suffixed with a short hash, so two
long names stay distinct). Two tools whose names sanitise to the same skill name cannot both be
skills: the first wins and the collision is logged. The model receives one function definition per
exposed tool on every turn, each with the tool's own argument schema. The description is
`<label>: <tool> — <first sentence>` (capped at 300 characters), followed by ` [personal data]` and/or
` [runs code, SQL or commands]` when the tool's name says so.
The full description and full schema arrive as just-in-time instructions after the first call to that
tool, and again in the error message if the call's arguments do not validate. A server's own
`instructions` from its handshake are sent once per request, with the first of its tools that is used.

Each call to a stdio server spawns one process, makes the one request, and lets the process exit.
Each call to an http server is one initialize handshake per PHP request followed by the call, with the
session id the server handed out and reused within that request; the protocol version the server
answers is taken as it is. There are no long-lived sessions of either kind.

Tool lists are fetched once per server and cached in the `mago_mcp` cache type for the configured
lifetime (an hour by default); a failed fetch is cached for five minutes so a down server costs one
attempt per five minutes, never one per admin page load. Enabling, disabling or rescanning a server
drops its cached list; `mago:mcp:refresh` drops it so the next use re-fetches.

While a call runs, the chat panel shows the skill's own name as the tag and a plain phrase built from
the tool name (`Getting order...`) as the status line.

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
- Anything else, including a name with no recognisable word at all, **fails closed to write**.
  `query` is deliberately not on the read list: a query can mutate.

A server's own annotations (`readOnlyHint`, `destructiveHint`) are read too, but only to make a tool
*stricter* than the name would suggest: `readOnlyHint: false` or `destructiveHint: true` can turn a
name-classified read into a write, never the other way around. A remote server is a third party and
is not trusted to declare its own tools safe.

Names can fool a word list: a name ending in a read noun is read even if its verb is unknown
(`rotate-log`). Before enabling a server, run `mago:mcp:list <server>`, which works on a disabled
server, and review its read tools; a mis-named tool is restricted through the role, the per-user
setting, or by disabling the server, until upstream fixes its name.

A tool's type is then fixed, exactly like one of Mago's own skills carrying its type in code: there is
no override, no `disabled` mode, and no hidden state. Who may use a tool is Mago's business alone, the
role ACL (`MCP Tools - Read` / `MCP Tools - Write`) and the per-user table on that tool's own Skills &
Permissions page. A `write` tool goes through Mago's confirmation card before it runs; a tool whose name
carries `delete`, `cancel`, `creditmemo`, `remove`, `drop`, `truncate` or `purge`, or whose server marks
it `destructiveHint: true`, is flagged irreversible and the card shows its impacts. Arguments are
validated for every tool, read or write: a missing required argument or an argument of the wrong type
(string, integer, number, boolean, array, object, null) is refused before anything runs, and for a write
before the card is shown.

A tool whose name says it runs code, SQL or commands (`exec`, `execute`, `eval`, `evaluate`, `run`,
`runner`, `script`, `shell`, `bash`, `terminal`, `console`, `cli`, `command`, `php`, `sql`, `unsafe`,
`generate`, `scaffold`, `reinitialize`, `install`, `uninstall`, `upgrade`, `migrate`, `batch`) carries
` [runs code, SQL or commands]` on its description and `execution` in `mago:mcp:list`. Tools that return
personal data by their name (`customer`, `order`, `invoice`, `shipment`, `creditmemo`, `address`,
`email`, `phone`, `account`, `user`, `subscriber`, `quote`, `cart`, `wishlist`, `review`, and their
plurals) carry ` [personal data]` the same way and `personal-data` in `mago:mcp:list`. Both flags are
information; neither changes a tool's type.

## Results and privacy

A tool's result reaches the model in one of two shapes, chosen by the server row's **output public**
flag. In this version only a module definition sets that flag (`outputPublic`); Composer and `.mcp.json`
rows are always non-public, and no administrator setting changes it yet:

- **Not public** (the default, and the right setting for a local server that returns customer data):
  the result is one text string, declared public so Mago's heuristic scrub and vault concealment run
  over the whole of it. Structured content with no text is JSON-encoded into that string. The heuristic
  catches emails, phone numbers and values the vault already knows; names and street addresses embedded
  in prose are not reliably caught.
- **Public**: the result crosses as data. The server's `structuredContent`, or a single text block that
  is JSON of an object or list, goes to the model as it is under a wildcard-public classification, or
  under the tool's own override from a module definition (the example above strips `email` from one
  tool's output while the rest is public). An override replaces the wildcard rule, so it must carry its
  own `*` entry or the result has no rule and is dropped; overrides apply only to a public server; the
  values are `public`, `tokenise` and `strip`. Choose public only for a server whose output is not
  personal data.

Text results are truncated to the configured maximum characters; data results are not, Mago's own
token cap applies to them.

A tool that reports an error returns Mago's own error shape; when a module definition carries an
`errorHints` entry whose fragment appears in the error, the hint is appended so the model knows what
to try next.

## Security

An execution tool, a code runner, a raw SQL query tool, is a write skill like any other MCP tool:
available, behind the confirmation card, to every admin whose role holds `MCP Tools - Write`. The
trade-off is deliberate: one confirmation click stands between that permission and arbitrary PHP or SQL
running on the store, in exchange for MCP tools behaving exactly like Mago's own skills. Restrict it
the way any of Mago's own write skills is restricted: grant `MCP Tools - Write` to few roles, set the
tool to Disabled for the users who should not have it, or disable the server.

Bearer tokens are encrypted in the database with Magento's encryptor, never logged, and never shown
in the grid or the CLI; a token written in `.mcp.json` or `di.xml` stays plain text in that file.
Nothing checks for `https://`, so an `http://` URL sends the token unencrypted. A remote server is a
third party: its `readOnlyHint: true` is not trusted, its result
is scrubbed as one string unless an administrator marks it public, and its instructions are truncated
and labelled as coming from the server. Prompt injection through tool results is not otherwise
mitigated.

A stdio server is spawned from the web request under the PHP user, inheriting the PHP process's whole
environment with the stored variables merged over it. Whatever a Composer `extra.mago-mcp` block, a
`.mcp.json` entry or a module definition stores is passed unchanged: whoever can edit any of those can
already set the executable, so treat that ability as the ability to run arbitrary code on the Magento
host.

## For package authors

A Composer package can declare its stdio server explicitly under `extra.mago-mcp` in its
`composer.json`:

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

`name` and `cwd` are optional; `command` is required and is run as given, one array entry per argv
entry (no shell parsing), except that a leading `php` is replaced by the PHP binary Magento runs under.
A relative `cwd` is resolved against the Magento root, which is also the default. Without an
`extra.mago-mcp` block, a package is still discovered if it ships a
`bin` entry whose basename contains `mcp` and does not end in `-docker`; that binary is run as
`php vendor/bin/<basename>`. For a remote server, ship a module definition instead (above).

## `.mcp.json` stdio entries

A stdio entry in the Magento root's `.mcp.json` (the file Claude Code and Cursor read) is discovered
when the option is on. A `docker exec ... <container> <command>` entry is unwrapped into the inner
command, keeping `-w` as the working directory and `-e KEY=VALUE` pairs as environment and dropping
`-u`, since that command is what actually needs to run once the module spawns it. This assumes Magento's
PHP runs inside that same container.

## Logging

A failed process spawn, an unreachable remote server, a malformed handshake, or a call that fails at the
transport or JSON-RPC level is written to `var/log/mago-error.log`; a tool that answers with `isError` is
returned to the model, not logged. When Mago's own debug flag is on, every call also
writes metadata, the skill, argument keys, whether the result was an error, and the result's block count
and character length, to `var/log/mago-debug.log`. Raw tool arguments, raw result text and tokens are
never written to any log.

## Limits of this version

- **Per-admin OAuth is not built yet.** An http row with `auth_type = oauth` exposes no tools.
- **No admin form for a remote server yet.** Add one through `.mcp.json` or a module definition.
- **The registry plugin depends on Mago's `ToolRegistry` and `ChatService`, not `@api`.** If Mago
  renames their public methods or the `tool_status` event shape, MCP skills disappear from the list or
  the status-line phrase falls back to Mago's generic message until the plugin is updated.
- **Tool arguments whose name starts with `_` are dropped** before the call: Mago reserves that prefix.
- **stdio and Streamable HTTP only.** The older HTTP+SSE two-endpoint transport is not supported.
- **Tools only.** MCP resources and prompts are not exposed.
- **No persistent processes or sessions.** A server that expects state between calls will not behave as
  its own documentation suggests.
- **No widgets.** Only a tool's text or structured content is used; image and other blocks are counted,
  not rendered.
- **One permission grant for all servers.** `MagoAssistant_Mcp::use` / `::use_write` are not split per
  server.
- **Protocol versions 2024-11-05 through 2025-06-18** are negotiated over stdio; HTTP speaks 2025-06-18.

## Development

From the Magento root:

```bash
vendor/bin/phpunit -c app/code/MagoAssistant/Mcp/phpunit.xml.dist app/code/MagoAssistant/Mcp/Test/Unit
vendor/bin/phpcs --standard=app/code/MagoAssistant/Mcp/phpcs.xml app/code/MagoAssistant/Mcp
```

or, from inside the module directory after `composer install`: `composer test` and `composer lint`.

Tests are unit tests with fakes, not mocks. The stdio transport tests run a real fake MCP server over a
real process (`Test/Unit/Fakes/fake-mcp-server.php`); the HTTP transport tests use Symfony's
`MockHttpClient`. That fixture is why `phpcs.xml` allows `exec`-family calls under `Test/`; production
code is covered by the standard sniffs except `Service/Mcp/StdioSession.php`, which disables the
process-function sniffs because it must use `proc_open`.
