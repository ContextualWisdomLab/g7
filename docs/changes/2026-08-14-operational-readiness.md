# Operational readiness and exact-head CI

## Added

- 운영자는 프로세스 생존 확인에는 `/up`, 실제 트래픽 투입 판단에는 `/ready`를 사용하십시오. `/ready`는 데이터베이스·캐시·Laravel 런타임 쓰기 경로가 모두 준비됐을 때만 성공하며, 실패하면 인스턴스를 트래픽 대상에서 제외하되 재시작하지 않습니다.
- 배포 전에 `READINESS_CHECKS`와 필요 시 `READINESS_STORAGE_PATH`를 확인하고, 로드 밸런서 또는 Kubernetes readiness probe가 HTTP 상태 코드로 `/ready`를 판정하도록 설정하십시오.
- 기여자는 새 PR에서 PHP 8.2·8.5와 Node.js 22·24 exact-head CI가 모두 완료됐는지 확인하십시오. 잠긴 의존성 설치, 보안 감사, PHP 포맷, MySQL 마이그레이션, 100% PHP coverage gate, 전체 Vitest와 production build가 검증됩니다.

## Security

- 상태 점검 응답은 실패한 의존성, 내부 호스트, 파일 경로, 예외 또는 자격증명을 공개하지 않습니다. 상세 원인은 인증된 관리·관측 계층에서 확인하십시오.
- CI의 외부 GitHub Actions는 전체 commit SHA로 고정되고 기본 토큰 권한은 읽기 전용입니다.
