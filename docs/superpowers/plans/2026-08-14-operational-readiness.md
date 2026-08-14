# Operational Readiness Implementation Plan

> Execute test-first. Do not merge until exact-head checks and independent review evidence are complete.

**Goal:** Separate application liveness from traffic readiness, add fail-closed dependency validation, document deployment usage, and establish repository-local exact-head CI.

**Architecture:** Laravel keeps `/up` for process liveness. A new controller delegates to a small readiness service that checks configured required dependencies without side effects and returns only a status token. The endpoint is registered outside stateful middleware groups. GitHub Actions validates PHP and JavaScript contracts; the organization scheduler remains the sole PR mutation authority.

**Tech stack:** PHP 8.2+, Laravel 12, PHPUnit 11, Mockery, MySQL 8.4, Node.js 22, Vite/Vitest, GitHub Actions.

---

## Task 1: Lock the public contract with failing tests

**Files**
- Create: `tests/Feature/Operations/SystemReadinessControllerTest.php`
- Create: `tests/Unit/Services/SystemReadinessServiceTest.php`

**Steps**
1. Add a feature test expecting unauthenticated `GET /ready` to return HTTP 200 and exact JSON when the service reports ready.
2. Add a feature test expecting HTTP 503 and exact non-diagnostic JSON when the service reports unavailable.
3. Assert no-store, no-cache, and nosniff headers on both outcomes.
4. Add unit tests for database, cache, storage, exception, unknown-check, empty-check, malformed-check, and malformed-storage-path branches.
5. Run the focused tests and record the expected RED result: missing service/controller/route.

## Task 2: Implement the readiness service

**Files**
- Create: `app/Services/SystemReadinessService.php`
- Create: `config/readiness.php`

**Steps**
1. Inject `DatabaseManager`, `CacheManager`, and the configuration repository.
2. Read an ordered required-check list from `readiness.checks`.
3. Fail closed when the list is empty, malformed, contains a non-string entry, or names an unsupported check.
4. Acquire the configured database connection without modifying data.
5. issue a cache read without creating a cache record.
6. Verify the configured runtime storage path is a directory and writable.
7. Catch every `Throwable` at the individual check boundary and return false without exposing or logging the exception.
8. Run unit tests until GREEN.

## Task 3: Implement the HTTP boundary

**Files**
- Create: `app/Http/Controllers/SystemReadinessController.php`
- Modify: `bootstrap/app.php`

**Steps**
1. Add an invokable final controller.
2. Map service readiness to HTTP 200/503.
3. Return only `status` in JSON.
4. Add no-store, no-cache, and nosniff headers.
5. Register `GET /ready` in the `withRouting(... then:)` callback outside `web` and `api` groups.
6. Preserve the existing `/up` liveness route unchanged.
7. Run feature tests until GREEN.

## Task 4: Document deployment and research traceability

**Files**
- Create: `docs/operations/health-probes.md`
- Create: `docs/doctoring/REFERENCES.md`
- Modify: `.env.example`
- Modify: `CHANGELOG.md`

**Steps**
1. Document liveness, readiness, and startup semantics.
2. Provide Kubernetes probe examples with bounded timeouts and status-code-based success.
3. Explain that the public response intentionally omits diagnostics.
4. Add `READINESS_CHECKS` and `READINESS_STORAGE_PATH` examples.
5. Add APA 7th references for Kubernetes probes, Laravel deployment health checks, NIST SSDF, and SLSA 1.2.
6. Add an Unreleased changelog section written as customer-action guidance.

## Task 5: Establish exact-head CI

**Files**
- Create: `.github/workflows/ci.yml`
- Create: `tests/Feature/Workflows/ContinuousIntegrationWorkflowTest.php`

**Steps**
1. Add a contract test that parses the workflow and verifies immutable action SHAs, read-only permissions, concurrency cancellation, PHP coverage, frontend test/build commands, and absence of Copilot tokens.
2. Add a MySQL-backed PHP job using locked Composer dependencies and Xdebug coverage.
3. Add a Node 22 job using `npm ci`, formatting, lint, test, and build commands.
4. Pin checkout, setup-php, and setup-node to exact commits.
5. Set `FORCE_JAVASCRIPT_ACTIONS_TO_NODE24=true` where applicable.
6. Run the workflow contract test and static YAML validation.

## Task 6: Verify and publish

**Steps**
1. Run focused unit and feature tests.
2. Run the full PHP suite and `composer test:coverage`.
3. Run `composer quality`.
4. Run `npm run format:check`, `npm run lint`, `npm run test:ci`, and `npm run build`.
5. Inspect the exact branch diff for unrelated changes and secrets.
6. Open one bounded pull request.
7. Review all current-head comments and unresolved threads.
8. Repair any check failures, rerun exact-head checks, and enable merge only after policy evidence is complete.
9. Re-enumerate open pull requests after merge and continue with the next highest-leverage buyer gap.
