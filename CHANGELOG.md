# Changelog

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
