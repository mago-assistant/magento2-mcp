# How this branch was merged, and the decisions behind it

Two implementations of "MCP servers for Mago" existed in this repository before
`feature/mcp-http-and-stdio`:

- **`feature/mcp-tools-as-skills`**: a stdio addon. Servers discovered from Composer packages and
  `.mcp.json`, one process per call, every tool its own Mago skill, a name classifier deciding read or
  write, argument coercion and validation before the confirmation card, an admin grid with enable,
  disable, rescan and refresh, 126 unit tests against a real fake process.
- **`feature/1-mcp-client`** and **`feature/2-rumvision-oauth`** (pull requests #3 and #4): a remote
  addon. Servers configured by URL in system configuration, a Streamable HTTP client with SSE
  responses and session handling, a bearer or per-admin OAuth authenticator (dynamic client
  registration, PKCE, encrypted tokens, refresh), a strict privacy default, and RUMvision as a built-in
  server.

Both used the package name `mago-assistant/magento2-mcp`, the module name `MagoAssistant_Mcp` and the
namespace `MagoAssistant\Mcp`, so a store could install one or the other, never both. The owner chose
to combine them rather than pick one.

## The mechanics

`feature/mcp-http-and-stdio` starts from the stdio branch's tree with a merge commit whose second
parent is `feature/2-rumvision-oauth` (`git merge --allow-unrelated-histories -s ours`). Both lineages
are in the history; the HTTP branch's code is ported in later commits with its author as co-author.
The design is written down in the Magento project's `docs/superpowers/specs/2026-09-26-mago-mcp-two-transports-design.md`
and delivered in four plans:

1. **Stdio on the new branch** (done): a transport seam, the full 1.0 schema, `mcp_<server>__<tool>`
   names, Mago's error shape, the public/non-public result rule, a five-minute failure cache.
2. **HTTP without OAuth** (done): `HttpTransport`, none and bearer authentication, module-shipped
   server definitions, `.mcp.json` http import, encrypted bearer tokens, allowed tools, server
   instructions once per request.
3. **OAuth and per-admin gating** (next): the OAuth authenticator and its controllers, a tool offered
   only to an admin who has connected, "replaces skill".
4. **Admin form and release** (after that): add, edit and delete a remote server by hand, Transport and
   Auth columns in the grid, token cleanup, README and CHANGELOG, phpstan, version 1.0.0.

## The key decisions

| Question | Decision | Why |
|---|---|---|
| Which transport? | Both, behind one `TransportInterface` with `StdioTransport` and `HttpTransport` chosen per server row by a `di.xml` map. | The local servers people actually run (Bricklayer, magerun) are stdio only; remote services are HTTP only. Everything above the seam is transport-blind. |
| Read or write? | The stdio branch's name classifier; a server's `readOnlyHint`/`destructiveHint` can only make a tool stricter. | A remote server is a third party. Trusting `readOnlyHint: true` would let it skip the confirmation card by annotation. |
| Privacy default? | The HTTP branch's: a server is not public unless an admin says so. But a non-public result crosses as **one string classified public**, not as stripped fields. | Mago's filter drops undeclared fields rather than scrubbing them; the literal HTTP-branch rule would have sent nothing at all from a local server. One scrubbed string keeps Bricklayer usable; a public server gets structured data. |
| Arguments? | The stdio branch's coercion and validation before the card. | The model sends `"3"` for an integer; refusing a call that would fail anyway spares the admin a pointless confirmation. |
| Names? | `mcp_<server>__<tool>`, lower-case, cut and hashed above 64 characters, from the HTTP branch. | One provider caps names at 64; server names are normalised to `[a-z0-9_]` so the name is unambiguous. |
| Where do servers live? | One table and one grid, the stdio branch's, grown with the http columns; four sources: module, Composer, `.mcp.json`, manual. | Any number of servers, each enabled or disabled, discovered or added, instead of one configurable server plus code-defined extras. |
| Per-tool controls? | None. A tool's type is fixed; who may use it is Mago's ACL and per-user table. | Both branches had already reached this; MCP tools behave exactly like Mago's own skills. |
| Vendor servers? | The module ships **no** vendor definition. Any module registers a `ServerDefinition` in `di.xml`; the registry's default is empty. | The owner has no RUMvision account or server, and a vendor server is that vendor's module's business. The mechanism is generic; the HTTP branch's `di.xml` and `config.xml` hold RUMvision's values for whoever builds that module. |
| Admin identity? | Explicit. Null means no user; a tool call carries the admin id today, and plan 3 makes the plugin and catalog pass it too; CLI and cron pass null. Nothing reads the admin session. | The HTTP branch silently fell back to the logged-in admin, which made "discovery with no user" mean different things in the web and the CLI. |
| OAuth for whom? | Manage-only in 1.0: connecting happens on the server edit page behind `MagoAssistant_Mcp::manage`. | Simplest secure layout; a connections page for admins with only the use permission can come later. |
| Version? | 1.0.0 on release (`composer.json` still says 2.0.0 until plan 4). | Neither branch was released; the stdio branch's "2.0.0" and the HTTP branch's "0.1.0" were internal. |
| History? | Both authors. The merge commit carries both parents; every commit that ports HTTP-branch code names its author as co-author. | The HTTP client, the authenticators and the OAuth code are that author's work. |

## What each branch contributed

From the stdio branch: discovery, the merger and scanner, the name classifier, the catalog and cache,
the skill wrapper, argument validation, the executor, the admin grid, the CLI, the registry and chat
plugins, the fakes and most tests.

New on this branch: `TransportInterface` and `TransportResolver`, `AuthenticatorResolver` and
`NoneAuthenticator`, `ServerDefinition`, `DefinitionRegistry` and `ModuleSource`, the `.mcp.json` http
import, `NameMigration`, and the four-source scanner.

From the HTTP branch: `AuthenticatorInterface`, `BearerTokenAuthenticator`, `HttpTransport` (its
`Client`), `McpAuthenticationException`, `InstructionGate`, the 64-character name guard, the result
mapping for structured content, the privacy default, the five-minute failure cache, the OAuth
tables, and, in plan 3, the whole `Service/OAuth` package and its controllers.

## What was consciously left out

- The HTTP branch's system-configuration groups per server, replaced by rows in the grid.
- Its "one custom server" model, replaced by any number of rows.
- Its trust in `readOnlyHint: true`.
- Its session fallback for the admin user id.
- Its built-in RUMvision server, replaced by the generic definition mechanism with an empty default.
- The stdio branch's per-tool override, `disabled` mode and shipped profiles (already gone in its own
  last phase).
- The stdio branch's `<server>__<tool>` names and its `content`/`is_error` result shape.
- The HTTP branch's `mago:mcp:tools --refresh` command (covered by `mago:mcp:list` and `mago:mcp:refresh`).
- Its `Api/ServerInterface` with `ToolProvider` and `ConfiguredServer` as the extension point, replaced
  by `ServerDefinition` in `di.xml`.
- Its `MagoAssistant_Mcp::config` ACL resource and its `magomcp` route; the grid's `::manage` resource
  and the `mago_mcp` front name serve instead.
- Its literal "strip every undeclared field" rule for a non-public server (see the privacy row above).
