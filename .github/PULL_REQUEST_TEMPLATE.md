<!--
  Thanks for contributing. Delete any section that does not apply rather than leaving it
  blank — an empty heading tells a reviewer less than no heading.
-->

## What this changes

<!-- One or two sentences. What is different after this is merged? -->

## Why

<!--
  The part a reviewer cannot reconstruct from the diff: what problem this solves, and why
  the obvious alternative was not chosen.
-->

Closes #

## How it was verified

<!--
  Say what you actually ran. "Loaded the board at 375px in Arabic and confirmed the columns
  mirror" is worth more than "tested".
-->

- [ ] `php artisan test` passes
- [ ] `php vendor/bin/pint --test` passes
- [ ] Schema changes were run against MySQL or MariaDB, not only SQLite

## Checklist

<!-- Tick what applies. Anything you skipped, say why. -->

- [ ] I read [CONTRIBUTING.md](../CONTRIBUTING.md)
- [ ] This follows [docs/ARCHITECTURE.md](../docs/ARCHITECTURE.md); if it changes the
      contract, that is called out below
- [ ] Any tenant-scoped query is **both** workspace-scoped and policy-checked
- [ ] Actions still do not authorize; the caller does
- [ ] New user-visible strings go through `__()`, and Arabic was added
      (`php artisan lang:scan` for literal keys, never hand-edit `lang/en.json`)
- [ ] New UI uses logical properties (`ms-`, `pe-`, `start-`, `border-e`) and no
      interpolated Tailwind classes
- [ ] Documentation updated in this same pull request, including
      [docs/LIMITATIONS.md](../docs/LIMITATIONS.md) if this made an entry untrue

## If this touches the AI layer

- [ ] The tool validates its arguments against its own schema
- [ ] It checks the permission **as the acting user**, not as the system
- [ ] It asserts workspace scope on every subject it resolves
- [ ] It mutates only through one `App\Actions\*` class
- [ ] Nothing it writes can contain a secret

## Screenshots

<!-- For anything visual, please include both directions: English and Arabic. -->

| English | العربية |
|---|---|
|  |  |
