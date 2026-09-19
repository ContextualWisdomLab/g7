# Operational Readiness Design

**Date:** 2026-08-14  
**Status:** Accepted for implementation  
**Scope:** GSeven application runtime and repository automation

## Problem

GSeven already exposes Laravel's built-in `/up` endpoint. That endpoint proves that the application can boot, which is appropriate for liveness, but it does not prove that the instance can safely accept user traffic. A process may boot while its database, cache, or writable runtime storage is unavailable.

Treating transient dependency failures as liveness failures would cause container restarts and can amplify an outage. The product therefore needs a distinct, low-cost readiness contract that removes an unhealthy instance from traffic without disclosing infrastructure details or forcing a restart.

The repository also has no repository-local workflow that continuously exercises its existing PHP and JavaScript quality contracts. The operational slice therefore includes an exact-head CI workflow. Pull-request maintenance remains delegated to the organization-owned reusable scheduler rather than duplicating policy code in this repository.

## Decision

### Endpoint separation

- Keep `/up` as the Laravel liveness endpoint.
- Add unauthenticated `GET /ready` as the traffic-readiness endpoint.
- Register `/ready` outside the `web` and `api` middleware groups so probes do not require sessions, authentication, localization, or API policy state.
- Return only `{"status":"ready"}` with HTTP 200 or `{"status":"not_ready"}` with HTTP 503.
- Never return connection names, hostnames, filesystem paths, exception messages, credentials, stack traces, or timing data.
- Send cache-prevention headers and `X-Content-Type-Options: nosniff`.

### Required dependency checks

The default required checks are:

1. successful read and write database queries;
2. cache read connectivity;
3. writable Laravel runtime storage.

GSeven supports split read/write database configuration. The database readiness check therefore executes a side-effect-free `SELECT 1` against the write path and then the read path; acquiring a cached PDO object alone is not evidence that either endpoint still accepts queries.

The ordered check list is configured through `READINESS_CHECKS`. Unknown, empty, or malformed check configuration fails closed. `READINESS_STORAGE_PATH` may override the default `storage/framework` path for non-standard deployments.

Checks are deliberately side-effect free: no queue jobs, rows, cache entries, or files are created by a probe request.

### Failure semantics

- Any configured dependency failure makes the whole endpoint return HTTP 503.
- Exceptions are converted to the same non-diagnostic response as ordinary failures.
- The service stops after the first dependency failure to keep probe cost bounded.
- The probe itself does not log each failure, avoiding log amplification during an outage. Infrastructure monitoring owns alert aggregation.

### Deployment contract

- Kubernetes liveness: `/up`.
- Kubernetes readiness: `/ready`.
- Startup probe: `/up` when slow startup requires a separate grace period.
- Probe clients use a short timeout and determine success from the HTTP status code.

### CI contract

The repository-local CI workflow shall:

- use immutable action commit references and non-persistent checkout credentials;
- grant read-only repository permissions;
- cancel superseded runs for the same pull request;
- validate Composer metadata, install and audit locked PHP dependencies, run Pint, migrate MySQL, and enforce the PHPUnit 100% coverage gate;
- test both the minimum supported PHP runtime and the current stable PHP runtime;
- install and audit locked Node dependencies, run the complete Vitest suite, and build production assets on the minimum and current LTS Node runtimes;
- contain no Copilot token and no privileged write token.

The existing organization scheduler remains the authority for review dispatch, branch refresh, exact-head check evaluation, auto-merge, and merge. It already runs more frequently than the requested hourly cadence, so a duplicate repository scheduler would create redundant Actions load and competing mutations.

## Security and compliance implications

The endpoint is intentionally public because load balancers and orchestrators commonly call it without application credentials. Its response is a one-bit traffic-admission signal, not a diagnostic interface. Detailed dependency diagnostics remain an authenticated administrative concern.

This design supports secure-development evidence expected by NIST SSDF and keeps workflow dependencies pinned in the direction required for stronger software-supply-chain provenance. It does not claim SOC 2 or CSAP certification by itself; it supplies auditable operational and CI controls that can contribute evidence to those programs.

## Alternatives rejected

1. **Add dependency checks to `/up`.** Rejected because database or cache incidents would become liveness failures and could trigger restart storms.
2. **Expose detailed per-dependency JSON.** Rejected because it provides unnecessary reconnaissance data and creates an unstable external contract.
3. **Protect `/ready` with application authentication.** Rejected because it couples orchestration to user/session infrastructure and can make the probe fail for the wrong reason.
4. **Treat PDO acquisition as a database probe.** Rejected because a previously created PDO object may exist after the remote database becomes unreachable; an actual read and write query is required.
5. **Create a second autonomous merge implementation in GSeven.** Rejected because organization policy already owns this concern and duplicate writers increase race and token risk.

## Acceptance criteria

- Healthy required dependencies produce HTTP 200 and the exact public JSON contract.
- Both database read and write paths must execute the side-effect-free probe query.
- Any required dependency failure or thrown exception produces HTTP 503 and the same non-diagnostic contract.
- Malformed readiness configuration fails closed before any dependency is contacted.
- The endpoint is uncached and unauthenticated.
- `/up` remains unchanged.
- Unit and feature tests cover every production statement and branch added by this slice.
- CI validates both PHP and JavaScript exact heads using locked dependencies.
- Operations and APA 7th research traceability documentation are present.
