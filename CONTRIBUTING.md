# Contributing

Thanks for taking a look. OpenRanch is free, MIT-licensed, and meant to stay
that way: anyone can use it, change it, run it for their own place or for
other people, and share what they build. The bar for changes is practical:
does it work on a real board, and is it clear six months later.

## How changes get in

`main` is protected. Nobody, including regular contributors, can push to it
directly. Every change goes through a pull request:

1. **Fork** the repository (the Fork button on GitHub), or, if you've been
   given write access, create a branch.
2. Make your change on a branch in your fork, e.g. `fix-flow-meter-units`.
3. Open a **pull request** against `main`.
4. CI runs automatically: a syntax check of every file, `php tests/run.php`,
   and a secret scan. The pull request can't merge until all of it passes.
5. A maintainer reviews it. It merges once it's approved, and your branch is
   deleted automatically.

Nothing on GitHub deploys anywhere. Merging to `main` doesn't touch anyone's
running dashboard, including the project's own. Each operator, including
the maintainers, decides when to copy a release onto their own server, and
they should try it on a staging copy first. So a pull request can't take a
working ranch offline.

Open an issue first for anything larger than a bug fix. It saves you building
something that doesn't fit. Questions and "look what I built" posts go in
Discussions.

## Ground rules

**Never commit `config.php`.** It holds the database password, the admin PIN,
the provisioning secret and the Web Push private key. It's gitignored, so keep
it that way. If you add a setting, add it to `config.example.php` with a
comment explaining what it does and a placeholder value.

**Don't rename the wire contract.** Device firmware in the field depends on
these staying exactly as they are:

- the `variable`, `value` and `cmd` JSON keys
- the `Device-Token` and `Provision-Key` headers
- the `ok` / `warn` / `alarm` state names
- every column name in `schema.sql`

Internal names are fair game. Anything a board reads or writes is not.

**Keep the device endpoints boring.** `ingest.php`, `poll.php`,
`register.php`, `lorawan.php` and `cam_upload.php` are spoken to by
microcontrollers and network servers with no error reporting. Keep the same
status codes and response keys, with no redirects and no cookies. A change
that makes a browser happier but adds a header to these isn't worth it.

**Monitoring, not life safety.** OpenRanch reports what sensors say and runs
the schedules you give it. Don't describe it, or build features into it, as a
safety or emergency system.

## Style

Match the file you're editing. It's plain PHP with no framework and no build
step. Comments explain *why*, not *what*, because the code already says what.

## Testing a change

1. `php tests/run.php` runs every test file and must pass. If you fix a bug,
   add a test in `tests/<area>_test.php` that would have caught it. Any file
   ending `_test.php` is picked up automatically.
2. Import `schema.sql` into a scratch database and click through the dashboard.
3. If you touched an endpoint, exercise it with `curl`. See
   [docs/firmware-payload.md](docs/firmware-payload.md) for working commands.
4. Say in the PR what hardware or simulated payload you tested against.

## Reporting a security issue

Please don't open a public issue. See [SECURITY.md](SECURITY.md).
