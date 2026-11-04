# Changelog

## Unreleased

Security hardening after an audit of 1.0.0 (2026-11-04). Nothing below changes a server row; roles may
need new grants.

### Changed

- **MCP tools require the Magento resource of the data they return.** A skill now also declares the
  resource its name points at (`Magento_Sales::sales_order` for order/invoice/shipment/creditmemo/quote/
  cart tools, `Magento_Customer::manage` for customer/address/subscriber/account/user/wishlist/email/
  phone tools, `Magento_Review::reviews_all` for reviews, `Magento_Config::config` for configuration),
  and Mago's `ToolAccess` requires it besides the module's own grant. Before, an admin kept out of
  Customers and Orders could read every customer and order through any MCP server. The map is
  `Service\Catalog\DataAcl`, extended from `di.xml`.
- **Execution tools have their own grant, `MagoAssistant_Mcp::execute`** ("MCP Tools - Execute code,
  SQL or commands"), which `MCP Tools - Write` no longer includes, and are always irreversible writes:
  the card asks for the acknowledgement and names the risk. Roles that used the code runner through
  `use_write` need `execute` now.
- **The read-only declaration no longer reaches an execution tool** or a tool the server annotates
  `readOnlyHint: false` / `destructiveHint: true`. Before, declaring Bricklayer read-only made
  `code-runner` and `batch-execute` cardless reads under the read grant.
- **`MagoAssistant_Mcp::manage` moved** from under Stores > Configuration to the assistant's own grants in
  the role tree, beside use/use_write/execute. Existing explicit grants keep working.
- **Allowed tools is a trust setting on every row**, discovered rows included, and a rescan keeps it.
  Before, only a manual row had the list, so a discovered server's code runner could be kept out only
  by disabling the server or editing every admin's permissions.
- **The admin pages are built like Mago's own.** The servers grid is a UI listing with filters, badges,
  the tool count and row actions, with Add Server, Rescan and Refresh Tool Lists as toolbar buttons; the
  edit page shows the scanned tools as a grid of checkboxes in three groups (reads, writes, execution
  and credential tools), each with its type, the Magento resource it needs, its description and a link
  to its Skills & Permissions page, followed by the trust settings and the actions.
- **Execution and credential tools are withheld until the tool list ticks them.** An empty list now
  means every tool except those; they stay listed (grid count, `mago:mcp:list` says `withheld`) but are
  not skills for anyone. A discovered and enabled Bricklayer or magerun therefore exposes its lookups
  and ordinary writes and nothing else. To keep using `code-runner` or `database-query`, write the
  tools you want into the server's list.
- **`.mcp.json` http entries follow the admin form's URL rule**: `https://`, or `http://` on a local host
  only; anything else is skipped and logged, so a bearer token in the file is never sent in clear.
- **Credentials in results are masked** (`Service\Privacy\SecretScrub`): a value after a key such as
  password, secret, api_key, token or dsn, an encrypted Magento blob, a bearer header, a password in a
  URL or a `mysql -p` string, an admin URL key, on every result path (text, structured, public data,
  error text, a server's own error message) and in the stdio debug log. A diagnostic read such as a
  database-info tool used to hand the model, and so the AI provider, the database password.
- **Raw database access is an execution surface** (`database`, `db` in a name), so `database-query`,
  `database-schema` and `db_info` need the execute grant and the acknowledged card; names that say
  they deal in credentials (`env`, `secret`, `password`, `token`, `key`, `decrypt`, ...) are writes for
  the card alone and never cardless reads; `schedule` is a write word. A CLI-style argument asking a
  server to decrypt or reveal secrets (`--decrypt`, `--show-secrets`, `--password`) is refused.
- **The read-only declaration lifts only the classifier's fail-closed caution**: a name that carries a
  write word (`delete`, `cancel`, `kill`, `schedule`) stays a write on a read-only server. Before, every
  tool of a read-only server was a read.
- **Diagnostics need the System resource**: `db`, `database`, `env`, `log`, `report` map to
  `Magento_Backend::system` in `DataAcl`.
- **A rescan that changes a server's connection withdraws the admin's approval.** When the source yields
  a different source, transport, command, environment, working directory, URL or auth type under a known
  name, the row goes back to disabled with read-only and public output cleared and the reason in its
  status, until an admin has seen the new details. Before, a package that took an `.mcp.json` server's
  name inherited its enabled and trust flags.
- **A server the last scan did not find is not exposed** while flagged Missing; its enabled flag is kept
  for when it returns. Before, it kept running on the stored command.
- **Text a server supplies is fenced in the just-in-time instructions**: its initialize instructions and
  a tool's description go to the model inside `<server_text>` markers with a note that they are data, the
  description capped at 2000 characters and a forged closing marker defused. Mago still sends the block
  as a system message; the host-side fix is noted in the README.
- **Transports cap one message at 8 MB** (stdio: the process is killed; HTTP: a declared or streamed body
  over the cap is refused), and a server's error text is bounded and masked like a result.
- **OAuth discovery validates the metadata**: the declared `resource` must be the MCP server or its own
  origin, an `issuer` in the authorization server metadata must match the server named, and every
  endpoint must be `https://` (or `http://` on a local host). Before, a server could have the admin consent
  to a token for a different resource at the same authorization server.

### Fixed

- The unit suite runs against the current Mago (`DebugLogger` takes the config repository).

### Added

- `Test/Unit/Security/EscalationScenariosTest.php`: the audit's escalation scenarios as regression tests,
  with the two still-open ones (customer-written text in results, vault tokens in remote read arguments,
  both Mago-side) marked as such.

## 1.0.0 — 2026-09-26

The first release. It combines two earlier, unreleased implementations of this package, a local (stdio)
addon and a remote (HTTP with OAuth) addon; `MERGE.md` explains how and why.

### Added

- **Two transports behind one interface.** Local servers over stdio, discovered from Composer packages
  and `.mcp.json`; remote servers over Streamable HTTP, from `.mcp.json`, a module definition, or the
  admin form.
- **Every tool of an enabled server is its own Mago skill**, `mcp_<server>__<tool>`, with Mago's own
  per-user permissions and confirmation card.
- **Admin form** under Stores > Admin Assistant > MCP Servers: add, edit and delete a remote server;
  `https://` required outside local hosts.
- **Authentication**: none, a bearer token (stored encrypted), or per-admin OAuth (dynamic client
  registration, PKCE, encrypted tokens, refresh). An OAuth server's tools reach only the admins who
  connected their own account.
- **Read-only servers**: an administrator can declare a server read-only, so its tools run without the
  confirmation card.
- **Public output**: a server marked public sends structured results to the model as data; otherwise
  each result is one string Mago's privacy scrub runs over.
- **Module definitions**: any module ships a server as a `ServerDefinition` in `di.xml`, with allowed
  tools, per-tool privacy overrides, error hints and a skill it replaces.
- `${VAR}` placeholders in `.mcp.json` bearer headers are read from the environment.
- CLI: `mago:mcp:discover`, `list`, `enable`, `disable`, `refresh`.
- phpstan (level 8) alongside phpcs and the unit suite.

### Changed (from the unreleased stdio addon)

- Skill names gained the `mcp_` prefix and are lower-case `[a-z0-9_]`, at most 64 characters.
- Server names are `[a-z0-9_]`; rows stored under an older name are renamed on `setup:upgrade`.
- A tool result is `result` (data or text) or Mago's `error` shape; `content` and `is_error` are gone.
- A failed tool list is cached for five minutes.

### Removed (from the unreleased HTTP addon)

- Per-server system configuration groups, replaced by rows in the servers grid.
- Trust in a server's `readOnlyHint: true`: a remote server can only make a tool stricter.
- The session fallback for the admin user id: it is always explicit.
- The built-in vendor server: the module ships no server definition of its own.
