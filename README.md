# 그누보드7 · Gnuboard7

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/ContextualWisdomLab/g7)

**Laravel 12 + React 기반의 확장형 CMS·커머스 플랫폼.**

이 저장소는 [`gnuboard/g7`](https://github.com/gnuboard/g7)의 **ContextualWisdomLab fork**입니다. 그누보드7의 제품·저작권·공식 배포 권위는 upstream에 있으며, 이 fork의 변경과 검증 상태는 이 저장소의 branch/PR 이력으로 별도로 판단해야 합니다.

그누보드7은 커뮤니티, 콘텐츠, 커머스 같은 웹 서비스를 하나의 코어 위에서 확장할 수 있도록 모듈·플러그인·템플릿·언어팩 구조와 JSON 기반 레이아웃 엔진을 제공합니다. 백엔드는 Laravel, 프론트엔드는 React/Vite를 사용합니다.

## 이 fork에서 무엇을 기대할 수 있나

현재 소스에서 확인되는 핵심 경계는 다음과 같습니다.

- **CMS 코어** — 사용자·권한·설정·콘텐츠와 공통 애플리케이션 기능을 Laravel 계층으로 제공합니다.
- **확장 구조** — 모듈, 플러그인, 템플릿, 언어팩을 통해 코어 수정 없이 기능과 화면을 확장할 수 있도록 설계돼 있습니다.
- **JSON 레이아웃** — 선언형 레이아웃을 React 컴포넌트로 렌더링하는 UI 확장 경계를 제공합니다.
- **인증·권한·운영 기반** — Sanctum 기반 인증, 역할/권한/스코프, 캐시·알림·검색·SEO 등 공통 서비스가 현재 코드와 문서에 존재합니다.
- **커머스 확장** — 쇼핑·결제 등은 코어와 확장 계약의 조합으로 다뤄지며, 외부 결제/본인인증 사업자의 권위나 약관을 이 저장소가 대체하지 않습니다.

상세 기능 목록과 upstream 설명은 [보존된 장문 제품 reference](UPSTREAM_README_REFERENCE.md)에서 확인할 수 있습니다. Root README는 fork 사용자와 통합자가 먼저 알아야 할 경계와 실행 경로만 유지합니다.

## 빠른 시작

현재 설치 문서는 PHP 8.2+, MySQL 8.0+ 또는 MariaDB 10.3+, Composer 2.x를 기본 요구사항으로 설명합니다. Redis 6.0+는 프로덕션 권장 선택 사항입니다. 정확한 환경·웹서버·공유호스팅 절차는 [`INSTALL.md`](INSTALL.md)를 먼저 확인하세요.

이 fork의 소스를 로컬에서 확인하려면:

```bash
git clone https://github.com/ContextualWisdomLab/g7.git
cd g7
composer install
npm ci
cp .env.example .env
php artisan serve
```

그 다음 브라우저에서 설치 마법사를 엽니다.

```text
http://localhost:8000/install
```

`package-lock.json`이 현재 프론트엔드 의존성을 고정하므로 깨끗한 checkout에서는 `npm ci`를 먼저 실행합니다. 그 뒤 `package.json`의 Vite/Vitest/Playwright 스크립트를 사용할 수 있습니다. PHP 테스트는 Composer가 제공하는 현재 repository contract를 따릅니다.

```bash
composer test
npm run test:run
npm run build
```

실제 배포 전에는 [`INSTALL.md`](INSTALL.md), 환경별 요구사항, 데이터베이스·캐시·메일·큐·웹서버 설정을 함께 검토하세요.

## 기술 및 통합 경계

현재 manifest 기준 주요 기술은 다음과 같습니다.

| 영역 | 현재 소스 경계 |
| --- | --- |
| Backend | PHP `^8.2`, Laravel `^12.0` |
| Frontend | React `^19.2.0`, Vite, Tailwind CSS 4 |
| Authentication | Laravel Sanctum |
| Realtime | Laravel Reverb / Echo 계열 |
| Search | Laravel Scout |
| Tests | PHPUnit 11.x, Vitest, Playwright |

이 표는 **현재 source manifest**를 요약한 것이며 hosted service, 지원 SLA, release artifact, 인증·컴플라이언스 획득을 뜻하지 않습니다.

다른 시스템과 통합할 때는 그누보드7의 코어 도메인과 extension point를 통해 연결하고, 결제·본인인증·메일·검색·스토리지 등 외부 사업자/라이브러리의 데이터·보안·라이선스 권위를 이 저장소로 흡수하지 마세요.

## 현재 상태와 release truth

이 ContextualWisdomLab fork는 현재 GitHub Releases를 게시하지 않았습니다. 따라서 기존 README에 있던 `7.0.5` 또는 `Stable` 표시는 **이 fork 자체의 immutable release/status 증거로 사용할 수 없습니다**.

공식 upstream 배포 상태가 필요하면 [`gnuboard/g7`](https://github.com/gnuboard/g7)의 release/tag/documentation을 확인하고, 이 fork를 사용할 경우에는 선택한 exact commit과 이 저장소의 현재 CI/review 상태를 별도로 검증하세요.

Fork source에 기능 코드가 존재한다는 사실만으로 production deployment, 고객 사용, 성능 benchmark, 보안 인증, 또는 upstream 지원 상태를 주장하지 않습니다.

## 문서

- [`INSTALL.md`](INSTALL.md) — 설치 요구사항과 환경별 설치 절차
- [`CHANGELOG.md`](CHANGELOG.md) — 이 source tree에 기록된 변경 이력
- [`docs/requirements.md`](docs/requirements.md) — 상세 환경 요구사항
- [`UPSTREAM_README_REFERENCE.md`](UPSTREAM_README_REFERENCE.md) — 이전 장문 README의 기능·아키텍처·비즈니스 설명 보존본
- [`AGENTS.md`](AGENTS.md) — 유지보수/자동화 지침; 일반 사용자용 제품 문서가 아님

## 기여 및 유지보수

변경 전 upstream과 이 fork의 차이를 확인하고, 기능을 추가할 때 코어·모듈·플러그인·템플릿의 책임 경계를 유지하세요. 사용자-visible 변경은 관련 PHP/React 테스트와 빌드 검증을 함께 갱신해야 합니다.

이 fork에서만 존재하는 변경을 upstream 기능처럼 표현하지 말고, upstream에서 가져온 변경을 ContextualWisdomLab 독점 구현처럼 표현하지 마세요. 병합 전에는 exact branch head의 테스트·보안·리뷰 결과를 사용해야 합니다.

## 라이선스와 provenance

그누보드7 원본 소스는 root [`LICENSE`](LICENSE)에 따라 **MIT License**로 배포되며, 저작권자는 **(주)에스아이알소프트 / SIRSOFT**로 명시돼 있습니다. 이 fork는 그 기존 저작권 고지와 허가 조건을 그대로 보존합니다.

ContextualWisdomLab이 이 fork를 유지보수한다는 사실은 upstream 저작권을 대체하거나 새 독점 라이선스를 부여하지 않습니다. Composer/npm 의존성, 외부 결제·인증·메일·검색 서비스, 배포 이미지와 기타 제3자 구성요소는 각자의 라이선스와 약관을 유지하며 root MIT가 이를 재라이선스하지 않습니다.
