# Contributing

Thanks for taking a look. This is a small project and the bar is practical:
does it work on a real board, and is it clear six months later.

## Before you start

Open an issue for anything larger than a bug fix. It saves you building
something that doesn't fit.

## Ground rules

**Never commit `config.php`.** It holds the database password, the admin PIN,
the provisioning secret and the Web Push private key. It is gitignored; keep it
that way. If you add a setting, add it to `config.example.php` with a comment
explaining what it does and a placeholder value.

**Don't rename the wire contract.** Device firmware in the field depends on
these staying exactly as they are:

- the `variable`, `value` and `cmd` JSON keys
- the `Device-Token` and `Provision-Key` headers
- the `ok` / `warn` / `alarm` state names
- every column name in `schema.sql`

Internal names are fair game. Anything a board reads or writes is not.

**Keep the device endpoints boring.** `ingest.php`, `poll.php` and
`register.php` are spoken to by microcontrollers with small stacks and no error
reporting. Same status codes, same response keys, no redirects and no cookies.
A change that makes a browser happier but adds a header to these is not worth
it.

## Style

Match the file you're editing — it's plain PHP with no framework and no build
step. Comments explain *why*, not *what*: the code already says what.

## Testing a change

There is no test suite. Before opening a PR:

1. `php -l` every file you touched.
2. Import `schema.sql` into a scratch database and click through the dashboard.
3. If you touched an endpoint, exercise it with `curl` — see
   [docs/firmware-payload.md](docs/firmware-payload.md) for working commands.
4. Say in the PR what hardware or simulated payload you tested against.

## Reporting a security issue

Please don't open a public issue. Email the address in `ALERT_EMAIL` on the
project's own deployment, or use GitHub's private vulnerability reporting.
