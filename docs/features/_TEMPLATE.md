# Feature Name

> File: `docs/features/<feature-name>.md` · Branch: `feature/<feature-name>` in each affected repo
> Status: draft | approved | in progress | done · Date: YYYY-MM-DD

## Goal

What the feature achieves and for whom (customer / staff role). Link the product spec in
`dahab-backend/docs/` if one exists.

## Impact Summary

```
Backend:      YES/NO
Database:     YES/NO
API:          YES/NO
Dashboard:    YES/NO
Customer App: YES/NO
Auth:         YES/NO
Permissions:  YES/NO
```

## Backend Impact

Actions, models, enums, policies/middleware, notifications, jobs. Files to add/change.

## Database Impact

Migrations (tables, columns, indexes, constraints), data backfill, seeders. `None` if none.

## API Changes

Per endpoint: method + path, surface (`/customer` or `/dashboard`), request fields + validation,
response shape, status codes, error `code`s. Classification: non-breaking / potentially breaking / breaking.
Only endpoints that exist or are added by this feature — never assumed ones.

## Dashboard Impact

Types, services (+ mocks), composables/stores, pages/components, navigation, permission gating.
`Not affected — <reason>` if none.

## Customer App Impact

Flutter models, API services, controllers/state, screens, tests (incl. fake backend shapes).
`Not affected — <reason>` if none.

## Authentication / Authorization

Which guard/surface; token abilities; who may call what; ownership rules.

## Permissions

New or reused staff permission strings; which roles get them (seeders); UI gating.

## Validation

Backend rules (authoritative) and any UX-only mirroring in the frontends.

## Error Handling

New/used error `code`s and how each client presents them.

## UI States

Loading / empty / error / success / permission-denied states for each affected screen, in each app.

## Testing

Backend Pest tests; Dashboard type-check/lint/build; Flutter analyze/test/build; manual/Postman steps.

## Breaking Changes

`None`, or the list with affected consumers and the versioning plan.

## Migration / Compatibility

Deploy order (Backend first), backward compatibility for older clients, data migration, rollback.
