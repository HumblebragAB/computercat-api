<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Redirect Domains
    |--------------------------------------------------------------------------
    |
    | These domains are the domains that OAuth clients are permitted to use
    | for redirect URIs. Each domain should be specified with its scheme
    | and host. Domains not in this list will raise validation errors.
    |
    | An "*" may be used to allow all domains.
    |
    */

    // DCR tillåts för claude.ai-connectorn och för Claude Code (CLI/VS Code),
    // som tar emot callbacken på en loopback-port (RFC 8252). 'http://localhost'
    // slår på paketets loopback-kontroll: exakt värd localhost, 127.0.0.1 eller
    // [::1], bara http, ingen användarinfo. Staff-inloggning och samtycke gäller
    // fortfarande, och oanvända klienter gallras av computercat:prune-oauth-clients.
    'redirect_domains' => [
        'https://claude.ai',
        'http://localhost',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Custom Schemes
    |--------------------------------------------------------------------------
    |
    | Native desktop OAuth clients like Cursor and VS Code use private-use URI
    | schemes (RFC 8252) for redirect callbacks instead of standard schemes
    | like HTTPS. Here, you may list which custom schemes you will allow.
    |
    */

    'custom_schemes' => [
        // 'claude',
        // 'cursor',
        // 'vscode',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization Server
    |--------------------------------------------------------------------------
    |
    | Here you may configure the OAuth authorization server issuer identifier
    | per RFC 8414. This value appears in your protected resource and auth
    | server metadata endpoints. When null, this defaults to `url('/')`.
    |
    */

    'authorization_server' => null,

    /*
    |--------------------------------------------------------------------------
    | Tool Search
    |--------------------------------------------------------------------------
    |
    | Here you may configure the limits enforced during tool search. The max
    | number of tool calls limits how many tools search requests can call
    | while the maximum output bytes value will limit the result sizes.
    |
    */

    'tool_search' => [
        'max_tool_calls' => 10,
        'max_output_bytes' => 65_536,
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP Clients
    |--------------------------------------------------------------------------
    |
    | Here you may configure named MCP clients that connect to external MCP
    | servers. Every client may define a URL for the remote server, or a
    | command and arguments for local servers plus connection options.
    |
    */

    // Vi använder inga utgående MCP-klienter. Nyckeln måste stå kvar som tom
    // lista: McpServiceProvider gör mergeConfigFrom, så en borttagen nyckel
    // fylls på med paketets exempel (GitHub, Linear, playwright via npx @latest).
    'clients' => [],

];
