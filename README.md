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

Version 1.0.0; see `CHANGELOG.md`. `MERGE.md` explains how two earlier implementations became this one.

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
| `composer` | stdio | a Composer package with an `extra.mago-mcp` block or an `*mcp*` binary | enable, disable, trust settings |
| `mcp_json` | stdio or http | the Magento root's `.mcp.json` (when enabled in config) | enable, disable, trust settings |
| `module` | stdio or http | a `ServerDefinition` any module registers in `di.xml` | enable, disable, trust settings |
| `manual` | http | an administrator, with Add server on the grid | everything |

Sources run in the order module, Composer, `.mcp.json`. The first source to yield a name wins; a later
copy of the same name is logged and dropped. A `manual` row is never touched by a scan.

Discovery never enables anything by itself. It only makes a server visible so an administrator can
turn it on. The source owns a discovered row's connection details; the administrator owns every row's
**trust settings**: enabled, read-only, public output and the allowed-tool list. A rescan re-applies the
connection details from the source and keeps the trust settings (and a stored bearer token when the
source supplies none); a source's values for the trust settings count only when the row is first
inserted. One exception: the approval was given to a particular command or URL. When a rescan finds a
different source, transport, command, environment, working directory, URL or auth type under a known
name (a package update, an edited `.mcp.json`, a package that took the name of an `.mcp.json` entry),
the row goes back to disabled with read-only and public output cleared and the reason in its status,
until an admin has looked at the new details and enabled it again. A server a source no longer yields, a
removed package, a disabled module, `.mcp.json` scanning switched off, is flagged Missing in the grid
and in `mago:mcp:discover` and is not exposed to the assistant while it is; its enabled flag is kept
for when it returns.

Names are normalised: lower-cased; every run of characters other than `a-z0-9_` becomes one `_`;
leading and trailing `_` are trimmed; then a leading `magento2_`, `magento_` and `module_` and a
trailing `_mcp_server` or `_mcp` are removed, each only if something remains. A package
`acme/magento-widget` and a `.mcp.json` entry "Magento Widget" both become `widget`: one row. `a-b` and
`a_b` are therefore the same server.

## Enable a server

In the admin: **Stores > Admin Assistant > MCP Servers** is a grid of known servers, built like Mago's
own Skills & Permissions grid: filterable columns for source, transport and authentication, the tool
count (exposed and withheld), the status, and per row Edit, Enable or Disable, Delete and the OAuth
connection; Add Server, Rescan and Refresh Tool Lists are the toolbar buttons.

**Edit** opens the server's page: an information table, then **Tools**, the scanned tool list as a grid
of checkboxes in three groups, reads, writes, and execution and credential tools. A ticked tool is
exposed as a skill; an unticked one is not a skill for anyone. Each row shows the tool's type, the
Magento resource a role needs for it, its first sentence, and a link to its Skills & Permissions page.
The dangerous group starts unticked. Below the tools come the trust settings: **read-only** (tools with
an unrecognised name run without the confirmation card) and **public output** (off: personal details
such as emails and phone numbers are hidden before a result goes to the AI; on: the AI sees the result
as the server sent it, so only for a server whose answers contain no people). On an OAuth server your
own connection follows; the actions are at the bottom, as on Mago's pages.

**Add server** opens the same page for a remote server with a connection section: label, URL,
authentication, bearer token, timeout, the skill it replaces, enabled. The name is derived from the label
once and never changes, because it keys OAuth tokens, the cache and the per-user permission rows. A blank
token field on edit keeps the stored token, which is never shown again, but only while the URL stays on
the same host: pointing the row at another host requires typing the token again. A discovered row shows
its connection details as text. **Delete** is offered on rows added here;
it removes every admin's OAuth tokens and the client registration with the row, and so does changing an
OAuth server's URL, whether on the form or through a rescan of its source.

Once a server is enabled, each of its ticked tools appears as its own row, `mcp_<server>__<tool>`, in
**Stores > Admin Assistant > Skills & Permissions** beside Mago's own skills, with Mago's own per-user
permission controls.

### Tools versus Skills & Permissions

Think of a building. The **Tools** list on a server's page decides which doors exist: an unticked tool
has no door, nobody can use it whatever their role, and the assistant is never told about it. You set
that once per server. **Skills & Permissions** decides who gets a key to each door that exists: per
admin user, read or write or disabled, falling back to the role's grants (`MCP Tools - Read`, `- Write`,
`- Execute`, and the Magento resource for the tool's data) when nothing is set for that user.

So untick a tool on the server page when nobody should ever have it, and use Skills & Permissions when
the tool should exist but only for some people. The Permissions link on each tool row takes you from the
first page to the second. Execution and credential tools start unticked.

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
JSON or SSE-formatted responses, a session id the server may hand out. Authentication is `none`,
`bearer` (a static token shared by every admin, stored encrypted), or `oauth`.

A 401 names the fix for the row's authentication: reconnect your account, check the bearer token, or
add authentication the server requires.

### OAuth: each admin connects their own account

For an `oauth` row, the grid and the edit page show **Connect my account** to the logged-in admin. It
registers this Magento install at the server's authorization server once (dynamic client registration,
RFC 7591, found through the server's own metadata), sends the admin there to log in (authorization code
with PKCE and the `resource` indicator), and stores the admin's token encrypted. Tokens are refreshed
shortly before they expire; a refresh the authorization server rejects disconnects the admin, an outage
does not.

- A connection belongs to one admin. An OAuth server's tools are offered, and run, only for admins who
  connected; nobody can use another admin's connection. Deleting an admin user deletes their tokens.
- The tool list is fetched the first time a connected admin needs it and then cached for everyone, so
  it shows on Skills & Permissions; only who may call the tools is per admin.
- Connecting is part of the servers page, which requires the `MCP Servers` resource (manage). An admin
  with only `MCP Tools - Read`/`Write` cannot connect and is therefore never offered an OAuth server's
  tools.
- **Replaces skill**: a Mago skill named on the row is hidden, and blocked, for every admin the server
  is offered to, so the assistant does not get the same data twice. Everyone else keeps the skill.

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
every rescan the definition wins over the row for the connection details, so a module update takes effect
without an admin action; `readOnly` and `outputPublic` count only when the row is first inserted, after
which they are the administrator's. The per-tool field classification and the error hints are never
stored: the catalog reads them from the definition each time, for the row the module created only.

A token given in `di.xml` stays plain text in that file and in `generated/` metadata; only the database
copy is encrypted. This module registers no definition itself. The registry's default is empty.

### Allowed tools

`allowedTools` is the list of tools a server may expose at all: a name not on the list is not a skill,
for anyone, whatever grants they hold. It is one of the administrator's trust settings on every row,
discovered or manual (the **Tools** checkboxes on the edit page), and a rescan keeps it; the source's
list counts when the row is first inserted.

**Empty means every tool except the dangerous ones.** A tool that runs code, SQL or commands or deals
in credentials (the execution and credential words under "Read or write") is *withheld* until the list
names it: listed in the grid's count and in `mago:mcp:list` as `withheld`, never a skill. So a server
that is discovered and enabled, Bricklayer or magerun, exposes its lookups and its ordinary writes and
nothing else; to use `code-runner`, write every tool you want, that one included, into the list. An
explicit list is exact: nothing outside it is exposed. The list also saves tokens: every exposed tool's
definition is sent to the model on every turn, so a server with sixty tools you use three of is worth
trimming. Who may call an exposed tool is still Mago's ACL and per-user table.

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

## Read-only servers

In plain terms: a tool whose name does not say whether it reads or changes something is treated as a
change and shows the confirmation card; on a read-only server such a tool runs at once. Tools that
clearly change something, run code or deal in credentials keep their card either way.

An administrator can declare a server **read-only** on its edit page. Every tool of that server whose
name the classifier below would have judged is then a `read` (shown as `read (server)` in
`mago:mcp:list`), runs without the confirmation card, and is never flagged irreversible. Use it for a
server whose tools cannot change anything, such as a documentation lookup; the classifier is
deliberately cautious and would otherwise send names like `resolve-library-id` or `query-docs` through
the card. A module definition can set `readOnly` as the starting value.

The declaration lifts one thing only: the classifier's fail-closed caution about a name it does not
recognise (`resolve-library-id`, `query-docs`). It never reaches:

- a name that carries a **write word** (`delete`, `cancel`, `kill`, `schedule`, ...): a delete is a
  delete on any server;
- an **execution tool** (a name carrying `exec`, `execute`, `run`, `runner`, `script`, `sql`, `php`,
  `shell`, `batch`, `database`, `db` and the rest of the list under "Read or write"): that stays an
  irreversible write under its own `MCP Tools - Execute` grant, because what runs is whatever the
  arguments say;
- a **credential tool** (a name carrying `env`, `secret`, `password`, `token`, `key`, `decrypt`, ...):
  a write for the card alone, never a cardless read;
- a tool the server itself annotates `readOnlyHint: false` or `destructiveHint: true`: a server that
  says one of its tools is destructive is believed, declared read-only or not.

So a read-only declaration on a server such as Bricklayer makes its lookups cardless and leaves
`code-runner`, `batch-execute`, `database-query` and `customer-delete` exactly where they were.

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
role ACL and the per-user table on that tool's own Skills & Permissions page. A `write` tool goes
through Mago's confirmation card before it runs; a tool whose name carries `delete`, `cancel`,
`creditmemo`, `remove`, `drop`, `truncate` or `purge`, or whose server marks it `destructiveHint: true`,
is flagged irreversible and the card shows its impacts and asks for the acknowledgement. Arguments are
validated for every tool, read or write: a missing required argument or an argument of the wrong type
(string, integer, number, boolean, array, object, null) is refused before anything runs, and for a write
before the card is shown.

A tool whose name says it runs code, SQL or commands, or touches the database directly (`exec`,
`execute`, `eval`, `evaluate`, `run`, `runner`, `script`, `shell`, `bash`, `terminal`, `console`, `cli`,
`command`, `php`, `sql`, `unsafe`, `generate`, `scaffold`, `reinitialize`, `install`, `uninstall`,
`upgrade`, `migrate`, `batch`, `database`, `db`) is an **execution tool**: always an irreversible write
(`write (execution)` in `mago:mcp:list`), whatever the server's annotations or the read-only
declaration say, carrying ` [runs code, SQL or commands]` on its description. A tool whose name says it
deals in credentials (`env`, `credential`, `secret`, `password`, `token`, `key`, `dsn`, `decrypt`, and
plurals) is a write for the card alone (`write (sensitive)`), never a cardless read. Tools that return
personal data by their name (`customer`, `order`, `invoice`, `shipment`, `creditmemo`, `address`,
`email`, `phone`, `account`, `user`, `subscriber`, `quote`, `cart`, `wishlist`, `review`, and their
plurals) carry ` [personal data]` and `personal-data` in `mago:mcp:list`; that flag is information and
changes no type, but the same words decide the Magento resource below.

Whatever a tool returns, credentials in it are masked before the model, the chat or a log sees it: a
value after a key such as `password`, `secret`, `api_key`, `token` or `dsn`, an encrypted Magento value,
a bearer header, a password inside a URL or a `mysql -p` string, an admin URL key. The masking is
pattern-based and errs on the side of masking. A CLI-style argument that asks a server to decrypt or
reveal secrets (`--decrypt`, `--show-secrets`, `--password`) is refused before the call.

### Which grants a call needs

Mago's `ToolAccess` requires every resource a call declares, and an MCP skill declares two:

| | resource | title in the role tree |
|---|---|---|
| a read tool | `MagoAssistant_Mcp::use` | MCP Tools - Read |
| a write tool | `MagoAssistant_Mcp::use_write` | MCP Tools - Write |
| an execution tool | `MagoAssistant_Mcp::execute` | MCP Tools - Execute code, SQL or commands |
| the servers page | `MagoAssistant_Mcp::manage` | MCP Servers (enable, trust settings) |

and, besides one of the first three, **the Magento resource of the data its name points at**, the way
Mago's own skills name theirs, so an admin Magento keeps out of Customers or Orders is kept out of them
through an MCP server too:

| word in the tool's name | resource required |
|---|---|
| `order`, `invoice`, `shipment`, `creditmemo`, `quote`, `cart` (and plurals) | `Magento_Sales::sales_order` |
| `customer`, `address`, `email`, `phone`, `account`, `user`, `subscriber`, `wishlist` (and plurals) | `Magento_Customer::manage` |
| `review`, `reviews` | `Magento_Review::reviews_all` |
| `configuration`, `config` | `Magento_Config::config` |
| `db`, `database`, `env`, `environment`, `log`, `logs`, `report`, `reports` | `Magento_Backend::system` |

Sales words are matched first, so `customer-orders` is a sales tool. A name with none of these words
(`product-list`, `module-list`, `code-runner`) needs only the module's own grant. The map is extended or
changed from any module's `di.xml` (`""` drops a default):

```xml
<type name="MagoAssistant\Mcp\Service\Catalog\DataAcl">
    <arguments>
        <argument name="overrides" xsi:type="array">
            <item name="ticket" xsi:type="string">Vendor_Helpdesk::tickets</item>
            <item name="review" xsi:type="string"></item>
        </argument>
    </arguments>
</type>
```

A tool the role may not run is not offered to the model at all; Mago checks the same resources again
when the tool runs, so a confirmed write proposed under one role is refused if the role changed.

All four of the module's resources sit under the assistant in the role tree (`Admin Assistant`), none
under Stores > Configuration: `manage` enables servers and sets their trust settings, which decides
what every admin's assistant may do, and `execute` is the web server user running whatever the
arguments say. Grant both to few roles.

## Results and privacy

A tool's result reaches the model in one of two shapes, chosen by the server row's **output public**
flag, one of the trust settings an administrator sets on the edit page (a module definition can set its
starting value with `outputPublic`). In plain terms: off hides personal details before the result goes
to the AI, on sends the result as the server returned it. Credentials are masked on both settings.

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

An execution tool, a code runner, a raw SQL tool, a batch runner, is arbitrary PHP or SQL running on
the store as the web server user, one confirmation away. It is therefore withheld by default: not a
skill until the server's **Allowed tools** names it. Once named, it has its own grant,
`MCP Tools - Execute`, which `MCP Tools - Write` does not include; it is always an irreversible write,
so the card asks for the acknowledgement and names the risk; and the read-only declaration never
reaches it. Grant `execute` to the few roles that deploy code. Credential tools (`env`, `secret`,
`password`, ... in the name) are withheld the same way.

What an MCP tool returns is gated by the Magento resource its name points at ("Which grants a call
needs" above), so the assistant cannot read customers, orders or configuration for an admin who may not
open those screens. The map is word-based; a server whose tool names hide what they return (`lookup`,
`fetch-record`) gets the module's own grant only. Review a new server's tools with `mago:mcp:list` before
enabling it, and extend the map from `di.xml` when a name needs a resource it does not get.

Bearer tokens are encrypted in the database with Magento's encryptor, never logged, and never shown
in the grid or the CLI; a token written in `.mcp.json` or `di.xml` stays plain text in that file. A URL
must be `https://`, or `http://` on a local host (`localhost`, `127.0.0.1`, `[::1]`, `.local`,
`.internal`, `.docker`, `.test`), on the admin form and in `.mcp.json` alike; an `http://` entry on
any other host is skipped and logged. An admin with `manage` can still point a server at any host the
Magento server can reach, including internal ones. A remote server is a third party: its
`readOnlyHint: true` is not trusted, its result is scrubbed as one string unless an administrator marks
it public, one message from it may not exceed 8 MB, and the text it supplies about itself (its
initialize instructions, a tool's description) goes to the model fenced as server-supplied data, capped,
with a forged closing marker defused. For an OAuth server, the metadata it points the admin at must name
the server itself as the resource, an issuer that matches, and `https://` endpoints.

**Prompt injection** is the open edge. Text a shopper typed, an order comment, an address line, a
review, a product question, reaches the model inside a tool result wrapped in Mago's `tool_output`
markers and a reminder that it is data; that is the only barrier between such text and the model's
next tool call. A read it talks the model into runs without a card; a write or an execution shows
the card, whose impacts for an execution tool now say that text the assistant read may be what asked
for the call. Three mitigations belong to Mago rather than this module and are not in place: marking
customer-written text in MCP results so it can never be copied into a write argument (Mago does this
for its own review skill); keeping vault tokens out of the arguments of a read call to a remote http
server, which today rehydrates them and sends the real value to that host; and sending a skill's
just-in-time instructions through the same `tool_output` envelope as a result instead of as a system
message, so the fence this module draws around server text carries no more authority than the text.
Also Mago's: a confirmed proposal runs every stored call, including one the card did not describe
because it was unavailable at proposal time; only the described calls should run.

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

## Limits

- **OAuth is manage-only.** Connecting happens on the servers page; an admin without that resource cannot
  connect and is never offered an OAuth server's tools.
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
- **One set of grants for all servers.** `MagoAssistant_Mcp::use` / `::use_write` / `::execute` are not
  split per server; the Magento data resources are per tool name, not per server.
- **Protocol versions 2024-11-05 through 2025-06-18** are negotiated over stdio; HTTP speaks 2025-06-18.

## Development

From the Magento root:

```bash
vendor/bin/phpunit -c app/code/MagoAssistant/Mcp/phpunit.xml.dist app/code/MagoAssistant/Mcp/Test/Unit
vendor/bin/phpcs --standard=app/code/MagoAssistant/Mcp/phpcs.xml app/code/MagoAssistant/Mcp
vendor/bin/phpstan analyse -c app/code/MagoAssistant/Mcp/phpstan.neon.dist app/code/MagoAssistant/Mcp
```

or, from inside the module directory after `composer install`: `composer test`, `composer lint` and `composer analyse`.

Tests are unit tests with fakes, not mocks. The stdio transport tests run a real fake MCP server over a
real process (`Test/Unit/Fakes/fake-mcp-server.php`); the HTTP transport tests use Symfony's
`MockHttpClient`. That fixture is why `phpcs.xml` allows `exec`-family calls under `Test/`; production
code is covered by the standard sniffs except `Service/Mcp/StdioSession.php`, which disables the
process-function sniffs because it must use `proc_open`.
