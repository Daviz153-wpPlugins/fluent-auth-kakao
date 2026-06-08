# CLAUDE.md — Fluent Auth Kakao

## 프로젝트

FluentAuth에 카카오 OAuth2 로그인을 추가하는 애드온 플러그인.
상세 스펙: `PROJECT.md`

## 환경

- PHP 8.2 / WordPress 7.0
- 의존: FluentAuth (필수), FluentCRM (선택 — 연락처 자동 등록용)
- 배포: GitHub 릴리즈 + Plugin Update Checker 자동 업데이트

## 절대 규칙

1. **FluentAuth 코어 파일 수정 금지** — 훅/필터만으로 구현
2. **최소 코드 원칙** — FluentAuth가 이미 하는 것을 중복 구현하지 않는다
3. **보안 우선** — nonce/state 검증, sanitize/escape 철저히
4. **커스텀 DB 테이블 없음** — wp_options + wp_usermeta만 사용
5. **발신자/세션 처리 = WordPress 기본** — `wp_set_auth_cookie()` 외 커스텀 세션 금지

## 개발 원칙

- **간결함**: 불필요한 코드 없음. 추상화는 3회 이상 반복될 때만.
- **디버깅 용이**: 클래스 하나에 역할 하나. 책임 명확히 분리.
- **보안 최우선**: 모든 입력 검증/이스케이프. state 없는 OAuth 콜백 불가.
- **직관적 UI**: 한국어 레이블/메시지. 비개발자도 설명 없이 사용 가능해야 함.

## 보안 체크리스트 (코드 작성 시 매번)

- [ ] 모든 PHP 파일 상단: `defined('ABSPATH') || exit;`
- [ ] OAuth 콜백: transient 기반 state 파라미터 CSRF 검증
- [ ] 카카오 API 응답값 전부 sanitize 처리
- [ ] 출력: `esc_html()`, `esc_attr()`, `esc_url()` 적용
- [ ] API 키/시크릿이 코드에 하드코딩되지 않음

## GitHub 업로드 금지 항목

`.gitignore`에 반드시 포함:
```
.env
*.log
/vendor/
wp-config.php
*-local.php
.DS_Store
node_modules/
```

커밋 전 `git diff --staged`로 개인정보 포함 여부 확인 필수.

## 파일 구조

```
fluent-auth-kakao/
├── fluent-auth-kakao.php        ← 진입점, 의존성 체크, 자동업데이트
├── includes/
│   ├── class-plugin.php         ← 부트스트랩 (싱글톤)
│   ├── class-kakao-handler.php  ← login_init + login_form 훅
│   ├── class-kakao-auth.php     ← OAuth HTTP 통신 (authorize→token→user/me)
│   └── class-settings.php       ← 관리자 설정 페이지
├── assets/js/admin.js           ← 설정 페이지 JS (필요 시)
├── CLAUDE.md
├── PROJECT.md
└── .gitignore
```

## 카카오 API 엔드포인트

| 단계 | URL |
|------|-----|
| 인증 요청 | `https://kauth.kakao.com/oauth/authorize` |
| 토큰 발급 | `https://kauth.kakao.com/oauth/token` |
| 사용자 정보 | `https://kapi.kakao.com/v2/user/me` |

## 이메일 없는 사용자 처리

카카오 계정에 이메일이 없거나 동의하지 않은 경우:
→ `kakao_{kakao_id}@kakao.user` 형식의 가상 이메일 자동 생성

## FluentCRM 통합 원칙

- FluentCRM 있을 때만 자동 등록 실행 (`defined('FLUENTCRM')` 체크)
- FluentCRM 없어도 카카오 로그인 자체는 정상 동작해야 함
- 클래스 사용 전 `class_exists()` 체크 필수

## 테스트 체크리스트

- [ ] FluentAuth 없을 때 관리자 차단 메시지 표시
- [ ] 카카오 로그인 버튼 wp-login.php에 표시 확인
- [ ] 신규 사용자: WP 계정 자동 생성 확인
- [ ] 기존 사용자: 동일 이메일 계정 자동 연결 확인
- [ ] FluentCRM 연락처 자동 등록 확인
- [ ] 이메일 없는 카카오 계정 → 가상 이메일 처리 확인
- [ ] state 파라미터 CSRF 방지 확인
- [ ] 설정 저장/불러오기 확인
