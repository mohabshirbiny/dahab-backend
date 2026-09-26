# Feature specifications

One file per cross-project feature: `docs/features/<feature-name>.md`, using the same `<feature-name>`
as the Git branch `feature/<feature-name>` in each affected repository.

Copy [`_TEMPLATE.md`](_TEMPLATE.md) and fill every section; write `None` / `Not affected — <reason>`
instead of deleting a section, so the impact analysis stays explicit.

Backend-internal Spec Kit specs stay in `dahab-backend/specs/`; link to them from here rather than
duplicating them.
