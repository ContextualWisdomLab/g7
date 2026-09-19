# GSeven 상태 점검 엔드포인트 운영 가이드

GSeven은 **프로세스 생존 여부**와 **실제 트래픽 처리 가능 여부**를 서로 다른 엔드포인트로 제공합니다. 운영자는 두 신호를 같은 목적으로 사용하지 않아야 합니다.

| 목적 | 엔드포인트 | 성공 | 실패 시 다음 행동 |
|---|---|---:|---|
| Liveness | `GET /up` | HTTP 200 | 프로세스가 부팅되지 않거나 복구 불가능한 상태인지 확인한 뒤 재시작 정책을 적용합니다. |
| Readiness | `GET /ready` | HTTP 200 | 인스턴스를 트래픽 대상에서 일시 제외하고 데이터베이스·캐시·스토리지 상태를 점검합니다. 프로세스는 계속 실행합니다. |

Laravel의 `/up`은 애플리케이션이 예외 없이 부팅되면 성공하는 기본 상태 경로입니다. GSeven의 `/ready`는 그 위에 데이터베이스 read/write 경로, 캐시, Laravel 런타임 쓰기 경로를 확인해 **사용자 요청을 받을 수 있는지** 판단합니다. 이 분리는 일시적인 의존성 장애를 프로세스 장애로 오인해 재시작이 반복되는 상황을 방지합니다.

## 공개 응답 계약

정상일 때:

```http
HTTP/1.1 200 OK
Content-Type: application/json
Cache-Control: max-age=0, no-store, private
Pragma: no-cache
X-Content-Type-Options: nosniff

{"status":"ready"}
```

트래픽을 받으면 안 될 때:

```http
HTTP/1.1 503 Service Unavailable
Content-Type: application/json
Cache-Control: max-age=0, no-store, private
Pragma: no-cache
X-Content-Type-Options: nosniff

{"status":"not_ready"}
```

응답에는 실패한 의존성 이름, 데이터베이스 호스트, 캐시 주소, 파일 경로, 예외 메시지, 스택 추적 또는 처리 시간이 포함되지 않습니다. 상세 진단은 인증된 관리 기능과 서버 관측 도구에서 수행하십시오.

애플리케이션 전체가 maintenance mode이면 전역 maintenance middleware가 `/ready`보다 먼저 503을 반환할 수 있으며 이때 응답 본문은 위 JSON과 다를 수 있습니다. 이것은 의도된 traffic-drain 동작이므로 로드 밸런서와 오케스트레이터는 본문 문자열이 아니라 HTTP 성공/실패 상태로 admission을 판단해야 합니다.

## 확인 항목 설정

기본 설정은 다음 세 항목을 순서대로 확인합니다.

```dotenv
READINESS_CHECKS=database,cache,storage
READINESS_CACHE_STORE=database
DB_CONNECT_TIMEOUT_SECONDS=1
REDIS_CONNECT_TIMEOUT_SECONDS=1
REDIS_READ_TIMEOUT_SECONDS=1
```

- `database`: write 경로와 read 경로에서 각각 side-effect가 없는 `SELECT 1`을 실행합니다. 별도 read replica를 쓰지 않는 환경에서는 두 경로가 같은 서버로 연결될 수 있습니다. MySQL 연결 시도는 기본 1초에 제한되고, readiness `SELECT` 자체도 MySQL `MAX_EXECUTION_TIME(1000)` optimizer hint로 1초 실행 예산을 갖습니다. 따라서 연결은 끝났지만 쿼리 처리가 멈춘 경우에도 probe가 무기한 워커를 점유하지 않습니다.
- `cache`: `READINESS_CACHE_STORE`로 지정한 실제 운영 캐시 저장소가 읽기 요청에 응답하는지 확인합니다. `array`, `null`, 빈/미정의 driver는 실제 의존성 가용성을 증명하지 못하므로 fail-closed 처리합니다. Redis 연결 및 read timeout 기본값은 각각 1초입니다.
- `storage`: Laravel 런타임 경로가 존재하고 쓰기 가능한지 확인합니다.

알 수 없는 항목, 빈 항목, 빈 목록 또는 잘못된 형식은 모두 `503 not_ready`로 처리됩니다. 필요한 점검을 실수로 비활성화하지 않도록 하는 fail-closed 계약입니다. `READINESS_CACHE_STORE`를 변경할 때에는 해당 store가 `config/cache.php`에 존재하고 실제 요청 경로에서 사용하는 운영 의존성을 대표하는지 확인하십시오.

비표준 배포에서만 런타임 경로를 바꾸십시오.

```dotenv
READINESS_STORAGE_PATH=/var/www/g7/storage/framework
```

환경변수를 바꾼 뒤 구성 캐시를 다시 만들고 `/ready`를 직접 호출해 상태 코드를 확인하십시오.

```bash
php artisan config:clear
php artisan config:cache
curl --fail-with-body --max-time 2 http://127.0.0.1/ready
```

## Kubernetes 예시

컨테이너 포트 이름이 `http`인 경우 다음과 같이 분리합니다.

```yaml
startupProbe:
  httpGet:
    path: /up
    port: http
  periodSeconds: 5
  timeoutSeconds: 2
  failureThreshold: 60

livenessProbe:
  httpGet:
    path: /up
    port: http
  periodSeconds: 10
  timeoutSeconds: 2
  failureThreshold: 3

readinessProbe:
  httpGet:
    path: /ready
    port: http
  periodSeconds: 5
  timeoutSeconds: 2
  successThreshold: 1
  failureThreshold: 2
```

배포 환경의 최악 부팅 시간을 먼저 측정한 뒤 `startupProbe.failureThreshold × periodSeconds`를 조정하십시오. Readiness 실패는 인스턴스를 Service 대상에서 제외하지만 컨테이너를 재시작하지 않습니다. Liveness 실패는 재시작으로 이어지므로 데이터베이스나 캐시처럼 외부 의존성의 일시 장애를 `/up` 실패로 변환하지 마십시오. 의존성의 연결·읽기·쿼리 실행 예산을 `readinessProbe.timeoutSeconds`보다 짧게 유지하여 애플리케이션 워커가 오케스트레이터의 전체 probe budget을 독점하지 않게 하십시오.

## 로드 밸런서와 외부 모니터

- 프로세스가 응답하는지만 확인하는 모니터는 `/up`을 사용합니다.
- 신규 연결을 보낼 수 있는지 판단하는 로드 밸런서는 `/ready`를 사용합니다.
- 성공 여부는 응답 본문 문자열이 아니라 HTTP 상태 코드로 판단합니다.
- 요청 제한 시간은 짧게 유지하되 실제 운영 네트워크 지연보다 낮게 설정하지 마십시오.
- `/ready`는 Laravel이 해석한 클라이언트 IP별로 분당 120회로 제한됩니다. 프록시·로드 밸런서 뒤에서는 trusted-proxy 구성이 실제 배포 토폴로지와 맞는지 확인하고, 여러 probe가 하나의 유효 클라이언트 IP로 합쳐질 경우 합산 요청률이 분당 120회를 넘지 않게 구성하십시오. `429 Too Many Requests`가 보이면 probe 주기·중복 모니터·proxy topology를 점검한 뒤 다시 확인하십시오.
- `/ready` 응답을 CDN, 프록시 또는 브라우저 캐시에 저장하지 마십시오. 응답 헤더가 이를 금지하지만 중간 장비 설정도 함께 확인하십시오.

## 장애 대응 순서

`/up = 200`, `/ready = 503`이면 다음 순서로 대응하십시오.

1. 인스턴스가 트래픽 대상에서 빠졌는지 확인합니다.
2. 최근 구성 변경과 비밀정보 회전을 확인합니다.
3. write DB와 read DB의 연결, 계정 권한, 연결 한도, replica 상태와 지연을 각각 확인합니다.
4. `READINESS_CACHE_STORE`가 실제 운영 store를 가리키는지 확인하고 캐시 연결 및 장애 조치 상태를 점검합니다.
5. `storage/framework`의 소유자, 그룹, 디스크 여유 공간과 쓰기 권한을 확인합니다.
6. 문제를 해결한 뒤 `/ready`가 연속으로 성공하는지 확인하고 트래픽을 복구합니다.

`/up`도 실패하면 애플리케이션 부팅 로그, PHP 프로세스, 구성 문법, 공급망 아티팩트와 배포 롤백 가능성을 먼저 확인하십시오.

## 보안 경계

이 경로는 오케스트레이터와 로드 밸런서가 애플리케이션 계정 없이 호출할 수 있도록 공개되어 있습니다. 따라서 응답은 한 비트의 트래픽 허용 신호만 제공합니다. 공개 프로브의 의존성 접근은 per-IP rate limit과 bounded connection/read/query-execution timeout으로 제한되며, 운영자는 상세 진단 값을 `/ready`에 추가하지 말고 접근 통제된 관측·관리 계층에 추가해야 합니다.

## 근거

설계 근거와 APA 7th 참고문헌은 [`docs/doctoring/REFERENCES.md`](../doctoring/REFERENCES.md)에 기록되어 있습니다.
