# Specification Quality Checklist: Tax invoices and credit notes (no ETA integration)

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-10-05
**Feature**: [spec.md](../spec.md)

## Content Quality

- [x] No implementation details (languages, frameworks, APIs)
- [x] Focused on user value and business needs
- [x] Written for non-technical stakeholders
- [x] All mandatory sections completed

## Requirement Completeness

- [x] No [NEEDS CLARIFICATION] markers remain (answered 2026-10-05: Q1 A, Q2 A, Q3 B)
- [x] Requirements are testable and unambiguous
- [x] Success criteria are measurable
- [x] Success criteria are technology-agnostic (no implementation details)
- [x] All acceptance scenarios are defined
- [x] Edge cases are identified
- [x] Scope is clearly bounded (no ETA filing, no market-maker / first-sale invoices)
- [x] Dependencies and assumptions identified

## Feature Readiness

- [x] All functional requirements have clear acceptance criteria
- [x] User scenarios cover primary flows
- [x] Feature meets measurable outcomes defined in Success Criteria
- [x] No implementation details leak into specification

## Notes

- Domain terms (ledger lines, row-level security, idempotency key, DModal) are kept because they are the platform's stated rules, not implementation choices.
- Seven further open points (a)–(g) are listed in the spec for `/speckit-clarify`.
- Disagreement reported: blueprint/prototype treat commission as VAT-inclusive; Part 3 and the built ledger add VAT on top — the invoice follows the ledger.
