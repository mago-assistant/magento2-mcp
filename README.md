# Mago Assistant: MCP servers

Add-on for [Mago Assistant](https://github.com/mago-assistant/mago) that lets the assistant call tools on remote
[MCP](https://modelcontextprotocol.io/) servers (Streamable HTTP, spec 2025-06-18). Mago itself stays free of MCP code;
this module hooks into its `ToolRegistry` with a plugin.

## Installation

```bash
composer require mago-assistant/magento2-mcp
bin/magento module:enable MagoAssistant_Mcp
bin/magento setup:upgrade
```

## Configuration

**Stores > Configuration > Mago Assistant > General > MCP Server**

| Field | |
|---|---|
| Server URL | The MCP endpoint, e.g. `https://example.com/mcp` |
| Bearer Token | Sent as `Authorization: Bearer` for every admin user; empty for servers without authentication |
| Allowed Tools | Comma-separated tool names; empty allows all (fewer tools = fewer tokens per message) |
| Output Contains No Personal Data | Only Yes when the server never returns personal data. With No, Mago's privacy filter strips every field |
| Timeout | Seconds per request |

Check the connection:

```bash
bin/magento mago:mcp:tools --refresh
```

## How it works

- All tools of a server become one assistant tool, `mcp_<code>`, with each remote tool as an `action`. It shows up on
  Mago's Skills page, so read/write grants per admin user apply.
- An action is read-only only when the remote tool declares `annotations.readOnlyHint: true`. Anything else is a write and
  goes through Mago's confirmation flow.
- The tool description carries the first sentence per action; the server's `instructions` and full descriptions are sent
  when the tool is first used.
- Tool lists are cached for an hour (5 minutes after a failure) and dropped when the configuration is saved.

## More servers

The admin-configured server is `ConfiguredServer` with code `custom` and config path `mago/mcp`. Add another one as a
virtualType plus a system.xml group with the same field ids:

```xml
<virtualType name="Vendor\Module\Mcp\AnalyticsServer" type="MagoAssistant\Mcp\Service\ConfiguredServer">
    <arguments>
        <argument name="code" xsi:type="string">analytics</argument>
        <argument name="configPath" xsi:type="string">vendor_module/mcp_analytics</argument>
    </arguments>
</virtualType>
<type name="MagoAssistant\Mcp\Service\ToolProvider">
    <arguments>
        <argument name="servers" xsi:type="array">
            <item name="analytics" xsi:type="object">Vendor\Module\Mcp\AnalyticsServer</item>
        </argument>
    </arguments>
</type>
```

Authentication is pluggable through `Api\AuthenticatorInterface`: `getHeaders()` receives the admin user id (null during
tool discovery) and `onUnauthorized()` may refresh credentials so the request is retried once.

## Tests

From the Magento root:

```bash
vendor/bin/phpunit -c vendor/mago-assistant/magento2-mcp/phpunit.xml.dist --bootstrap vendor/autoload.php
```
