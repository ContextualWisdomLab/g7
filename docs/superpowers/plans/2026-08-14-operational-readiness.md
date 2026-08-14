# Operational Readiness Implementation Plan

> Execute test-first. Do not merge until exact-head checks and independent review evidence are complete.

**Goal:** Separate application liveness from traffic readiness, add fail-closed dependency validation, document deployment usage, and establish repository-local exact-head CI.

**Architecture:** Laravel keeps `/up` for process liveness. A new controller delegates to a small readiness service that checks configured required dependencies without side effects and returns only a status token. A dedicated service provider registers the endpoint outside stateful middleware groups. GitHub Actions validates existing PHP and JavaScript contracts; the organization scheduler remains the sole PR mutation authority.

**Tech stack:** PHP 8.2 and 8.5, Laravel 12, PHPUnit 11, Mockery, MySQL 8.4 LTS, Node.js 22 and 24, Vite/Vitest, GitHub Actions.

---

## Task 1: Lock the public contract with failing tests

**Files**
- Create: `tests/Feature/Operations/SystemReadinessControllerTest.php`
- Create: `tests/Unit/Services/SystemReadinessServiceTest.php`

**Steps**
1. Add a feature test expecting unauthenticated `GET /ready` to return HTTP 200 and exact JSON when the service reports ready.
2. Add a feature test expecting HTTP 503 and exact non-diagnostic JSON when the service reports unavailable.
3. Assert cache prevention and nosniff headers on both outcomes.
4. Add unit tests for database, cache, storage, exception, unknown-check, empty-check, malformed-check, and malformed-storage-path branches.
5. Commit the expected RED specification before production implementation.

## Task 2: Implement the readiness service

**Files**
- Create: `app/Services/SystemReadinessService.php`
- Create: `config/readiness.php`

**Steps**
1. Inject `DatabaseManager`, `CacheManager`, `Filesystem`, and the configuration repository.
2. Read an ordered required-check list from `readiness.checks`.
3. Validate the complete list before contacting any dependency.
4. Fail closed when the list is empty, malformed, contains a non-string entry, or names an unsupported check.
5. Acquire the configured database connection without modifying data.
6. Issue a cache read without creating a cache record.
7. Verify the configured runtime storage path is a directory and writable.
8. Catch every `Throwable` at the individual check boundary and return false without exposing or logging the exception.

## Task 3: Implement the HTTP boundary

**Files**
- Create: `app/Http/Controllers/SystemReadinessController.php`
- Create: `app/Providers/OperationalReadinessServiceProvider.php`
- Modify: `bootstrap/providers.php`

**Steps**
1. Add an invokable controller and map service readiness to HTTP 200/503.
2. Return only `status` in JSON.
3. Add cache-prevention and nosniff headers.
4. Register `GET /ready` through a dedicated service provider without web/API middleware.
5. Preserve the existing `/up` liveness route unchanged.

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
5. Add APA 7th references for HTTP semantics/caching, Kubernetes probes, Laravel health routing, NIST SSDF, and SLSA 1.2.
6. Add an Unreleased changelog section written as customer-action guidance.

## Task 5: Establish exact-head CI

**Files**
- Create: `.github/workflows/ci.yml`
- Create: `tests/Feature/Workflows/ContinuousIntegrationWorkflowTest.php`

**Steps**
1. Add a contract test that verifies immutable action SHAs, non-persistent checkout credentials, read-only permissions, concurrency cancellation, PHP coverage, frontend test/build commands, and absence of Copilot tokens.
2. Add MySQL-backed PHP jobs for PHP 8.2 and 8.5 using locked Composer dependencies.
3. Run Composer validation/audit, Pint, migrations, and the PHPUnit 100% coverage gate.
4. Add Node.js 22 and 24 jobs using `npm ci`, production dependency audit, the complete Vitest suite, and production build.
5. Pin checkout, setup-php, and setup-node to exact commits.
6. Set `FORCE_JAVASCRIPT_ACTIONS_TO_NODE24=true`.

## Task 6: Verify and publish

**Steps**
1. Run focused unit and feature tests through exact-head CI.
2. Run the complete PHP suite with `--coverage --min=100` on both supported runtime edges.
3. Run Composer validation/audit and Pint.
4. Run `npm ci`, production dependency audit, `npm run test:run`, and `npm run build` on Node.js 22 and 24.
5. Inspect the exact branch diff for unrelated changes and secrets.
6. Open one bounded pull request.
7. Review all current-head comments and unresolved threads.
8. Repair any check failures, rerun exact-head checks, and merge only after policy evidence is complete.
9. Re-enumerate open pull requests after merge and continue with the next highest-leverage buyer gap.
