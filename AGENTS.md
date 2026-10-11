# AGENTS.md — PowrSync

This repository is the root of the external Dolibarr module `powrsync`.

- Keep changes inside this module; do not modify Dolibarr core.
- Target Dolibarr v20–v25 and PHP 8.0+; distinguish simulated and real tests.
- Use native permissions and constants directly; preserve configured values on activation and deactivation.
- Synchronization logs belong to the entity executing the synchronization. Never infer ownership of legacy logs from shared products.
- Do not log credentials, tokens, cookies or raw authentication response headers.
- Keep configuration entry limited to `setup.php@powrsync` and versions aligned with `ChangeLog.md`.
