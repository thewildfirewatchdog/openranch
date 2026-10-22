## What this changes

<!-- One or two sentences. Link the issue if there is one: "Fixes #12". -->

## How I tested it

<!-- What board, simulator or curl payload did you run it against? Paste the
     output of `php tests/run.php`. -->

## Checklist

- [ ] `php tests/run.php` passes
- [ ] No `config.php`, passwords, tokens, keys or real device MACs in the diff
- [ ] Device endpoints (`ingest.php`, `poll.php`, `register.php`, `lorawan.php`,
      `cam_upload.php`) answer exactly as before: same keys, same status codes
- [ ] New settings are added to `config.example.php` with a comment and a placeholder
- [ ] No column, JSON key or header that firmware uses was renamed
