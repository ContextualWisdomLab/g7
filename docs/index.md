# 그누보드7 · Gnuboard7

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/ContextualWisdomLab/g7)

그누보드7은 Laravel 12와 React를 기반으로 모듈·플러그인·템플릿·언어팩·JSON 레이아웃을 조합하는 CMS·커머스 플랫폼입니다. 이 저장소는 [`gnuboard/g7`](https://github.com/gnuboard/g7)의 **ContextualWisdomLab fork**이며, 원본 제품·저작권·공식 배포 이력의 권위는 upstream에 있습니다. Fork-specific 기능·검증·릴리스 상태는 사용하려는 commit과 이 저장소의 현재 CI/review evidence를 기준으로 별도로 확인해야 합니다.

## 시작하기

아래 repository 링크는 **보호된 기본 branch에 통합된 현재 공개 문서**를 따라갑니다. 아직 병합되지 않은 PR이나 과거 commit을 검토할 때는 해당 GitHub revision의 파일을 직접 열어 설치 지침과 manifest가 같은 revision인지 확인하세요.

- [Repository README](https://github.com/ContextualWisdomLab/g7#readme)
- [설치 가이드](https://github.com/ContextualWisdomLab/g7/blob/main/INSTALL.md)

현재 소스는 PHP 8.2+, Laravel 12, React/Vite와 MySQL 또는 MariaDB 기반 런타임을 전제로 합니다. 프론트엔드 빌드·테스트에는 Vite 7의 현재 지원 범위인 **Node.js 20.19+ 또는 22.12+**가 필요합니다.

깨끗한 source checkout의 기본 흐름은 다음과 같습니다.

```bash
git clone https://github.com/ContextualWisdomLab/g7.git
cd g7
composer install
npm ci
cp .env.example .env
php artisan serve
```

그 다음 `http://localhost:8000/install`에서 설치 마법사를 열고 데이터베이스·캐시·메일·큐·웹서버 환경을 구성합니다. `npm ci`는 현재 `package-lock.json`에 고정된 Vite/Vitest/React 개발 의존성을 설치하므로 프론트엔드 테스트나 빌드 전에 필요합니다.

## 제품 경계

이 source tree는 커뮤니티·콘텐츠·커머스 경험을 구성하기 위한 CMS 코어와 확장 모델을 제공합니다. 인증/권한, 콘텐츠·설정, 모듈형 UI/레이아웃 조합, 검색/SEO, 알림 등은 그누보드7 애플리케이션 경계 안에 있습니다. 결제, 본인인증, 메일, 외부 저장소 같은 공급자는 자신의 데이터·보안·가용성·라이선스 권위를 유지합니다.

다른 ContextualWisdomLab 제품과 연결할 때는 공개 API나 extension contract를 사용하고 다른 서비스의 private persistence에 결합하지 마세요.

## 아키텍처와 확장 모델

백엔드는 Laravel/PHP, 프론트엔드는 React/Vite입니다. 모듈·플러그인·템플릿·언어팩은 코어를 직접 수정하지 않고 기능과 화면을 조합하는 확장 경계를 제공하며, JSON 기반 레이아웃은 React 컴포넌트 렌더링을 위한 선언형 구조를 제공합니다.

이 설명은 현재 source boundary를 요약한 것이며 hosted service, SLA, 인증 획득, 고객 배포 상태를 뜻하지 않습니다.

## 검증과 릴리스 상태

검토 중인 revision에서 먼저 의존성을 설치한 뒤 저장소가 제공하는 테스트와 빌드 진입점을 실행하세요.

```bash
composer test
npm run test:run
npm run build
```

이 ContextualWisdomLab fork는 현재 독립적인 GitHub Release를 게시하지 않았습니다. source tree의 버전 문자열이나 upstream changelog만으로 이 fork가 immutable release를 배포했다고 판단하지 마세요. upstream 릴리스 권위는 [`gnuboard/g7`](https://github.com/gnuboard/g7)에서, fork-specific 변경은 이 저장소의 protected branch·CI·review evidence에서 확인합니다.

## 문서

- [README](https://github.com/ContextualWisdomLab/g7#readme) — 보호된 기본 branch의 제품 개요, fork 경계, 빠른 시작, release truth
- [설치 가이드](https://github.com/ContextualWisdomLab/g7/blob/main/INSTALL.md) — 보호된 기본 branch의 환경 요구사항과 설치 절차
- [변경 이력](https://github.com/ContextualWisdomLab/g7/blob/main/CHANGELOG.md) — 보호된 기본 branch의 source-tree 변경 기록
- [역사적 upstream README 원문 보존본](https://github.com/ContextualWisdomLab/g7/blob/main/UPSTREAM_README_REFERENCE.md) — 이전 upstream-oriented README의 byte-for-byte snapshot; 내부 `version-7.0.5`·`Stable` 표시는 현재 fork 상태가 아님
- [라이선스](https://github.com/ContextualWisdomLab/g7/blob/main/LICENSE) — upstream MIT grant와 SIRSOFT 저작권 고지
- [Ask DeepWiki](https://deepwiki.com/ContextualWisdomLab/g7) — 저장소 탐색과 질의

이 파일은 공개 문서의 **source prerequisite**일 뿐입니다. GitHub Pages는 repository settings, deployment 상태, 실제 HTTPS 페이지를 별도로 확인한 뒤에만 게시된 것으로 간주합니다.
