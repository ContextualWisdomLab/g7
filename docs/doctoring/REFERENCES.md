# Doctoring References

이 문서는 GSeven의 운영·보안·품질 결정을 뒷받침하는 권위 있는 자료를 APA 7th 형식으로 기록합니다. 참고문헌을 추가할 때에는 해당 자료가 실제로 뒷받침하는 코드·ADR·운영 문서를 함께 연결하십시오.

## Operational readiness and HTTP behavior

Fielding, R., Nottingham, M., & Reschke, J. (2022). *HTTP semantics* (RFC 9110). Internet Engineering Task Force. https://doi.org/10.17487/RFC9110

Kubernetes Authors. (2026, April 17). *Configure liveness, readiness and startup probes*. Kubernetes. https://kubernetes.io/docs/tasks/configure-pod-container/configure-liveness-readiness-startup-probes/

Laravel. (n.d.). *Deployment: The health route*. Laravel 12.x documentation. Retrieved August 14, 2026, from https://laravel.com/docs/12.x/deployment#the-health-route

Fielding, R., Nottingham, M., & Reschke, J. (2022). *HTTP caching* (RFC 9111). Internet Engineering Task Force. https://doi.org/10.17487/RFC9111

### Decision traceability

| Decision | Supporting source | Repository evidence |
|---|---|---|
| Separate traffic readiness from restart-oriented liveness | Kubernetes Authors (2026) | `docs/superpowers/specs/2026-08-14-operational-readiness-design.md`; `docs/operations/health-probes.md` |
| Preserve Laravel `/up` as the application-boot signal | Laravel (n.d.) | `bootstrap/app.php` |
| Use HTTP 503 when the instance must not receive traffic | Fielding et al. (2022) | `app/Http/Controllers/SystemReadinessController.php` |
| Prevent readiness responses from being reused by caches | Fielding et al. (2022) | `app/Http/Controllers/SystemReadinessController.php` |

## Secure software development and supply chain

SLSA Community. (2025, November 24). *SLSA specification version 1.2*. https://slsa.dev/spec/v1.2/

Souppaya, M., Scarfone, K., & Dodson, D. (2022). *Secure software development framework (SSDF) version 1.1: Recommendations for mitigating the risk of software vulnerabilities* (NIST Special Publication 800-218). National Institute of Standards and Technology. https://doi.org/10.6028/NIST.SP.800-218

### Decision traceability

| Decision | Supporting source | Repository evidence |
|---|---|---|
| Run repeatable automated verification on every proposed change | Souppaya et al. (2022) | `.github/workflows/ci.yml`; `tests/Feature/Workflows/ContinuousIntegrationWorkflowTest.php` |
| Pin third-party workflow actions to immutable commits | SLSA Community (2025) | `.github/workflows/ci.yml` |
| Keep workflow permissions read-only unless a narrowly scoped write is required | SLSA Community (2025); Souppaya et al. (2022) | `.github/workflows/ci.yml` |
