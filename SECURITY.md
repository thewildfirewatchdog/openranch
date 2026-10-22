# Security

## Reporting a problem

Please **don't open a public issue** for a security problem. Report it
privately instead:
https://github.com/thewildfirewatchdog/openranch/security/advisories/new

Say what you found, how to reproduce it, and what someone could do with it.
You'll get a reply as soon as someone can look. This is a volunteer project,
so please allow a few days.

## What's in scope

The code in this repository: the dashboard, the device endpoints, the API, the
Telegram bot and the MCP server.

Problems with a particular person's installation (a weak password, an
unpatched server, a leaked `config.php`) are for that operator to fix. They
aren't bugs in OpenRanch, unless the defaults or docs led them there.

## Keeping your own install safe

- Never commit or publish `config.php`. It holds every secret the site has.
- Serve the site over HTTPS only.
- Use a long random `ADMIN_PIN` and `PROVISION_KEY` (the commands are in
  `config.example.php`).
- Keep the web server from serving `.sql`, `.bak` and dotfiles. The example
  nginx config in the README does this.
