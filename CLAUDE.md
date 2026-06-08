# CLAUDE.md — Fluent Auth Kakao

## 프로젝트

FluentAuth에 카카오 OAuth2 로그인을 추가하는 애드온 플러그인.
상세 스펙: `PROJECT.md`

## 환경

- PHP 8.2 / WordPress 7.0
- 의존: FluentAuth (필수), FluentCRM (선택 — 연락처 자동 등록용)
- 배포: GitHub 릴리즈 + Plugin Update Checker 자동 업데이트

---

## 개발 5대 원칙

### 1. 코드는 최대한 간결하게

- **FluentAuth 리소스 우선** — 훅/필터만으로 구현, 코어 파일 수정 금지, 중복 구현 금지
- 기능 구현에 필요한 최소한의 코드만 작성
- 추상화는 동일 로직이 3회 이상 반복될 때만
- 미래를 위한 코드 작성 금지 (지금 필요한 것만)
- 커스텀 DB 테이블 없음 — wp_options + wp_usermeta만 사용

### 2. 오류를 쉽게 찾을 수 있게

- 클래스 하나 = 역할 하나 (단일 책임 원칙)
- OAuth 각 단계(authorize → token → user/me) 실패를 `WP_Error`로 명시 반환
- 조용한 실패(silent fail) 금지 — 어디서 무엇이 왜 실패했는지 메시지에 포함
- FluentAuth/FluentCRM 없을 때 조용히 넘어가지 않고 관리자 알림 표시
- 에러 발생 시 로그인 페이지로 리다이렉트 + `login` 파라미터로 오류 구분

### 3. 보안 강화

- 모든 PHP 파일 상단: `defined('ABSPATH') || exit;`
- OAuth state 파라미터: transient 기반 CSRF 방지 (5분 유효)
- 카카오 API 응답값 전부 `sanitize_*()` 처리 후 사용
- 모든 출력: `esc_html()` / `esc_attr()` / `esc_url()` 적용
- API 키/Client Secret 하드코딩 절대 금지 — DB(wp_options)에만 저장
- 설정 페이지: `manage_options` 권한 체크 필수

### 4. UI/UX는 직관적으로

- 한국어 레이블/메시지 (비개발자 기준)
- 카카오 공식 버튼 스타일 (#FEE500) — 사용자가 카카오 로그인임을 즉시 인식
- 설정 페이지에 카카오 개발자 콘솔 설정 가이드 인라인 제공
- 로그인 실패 시 이유를 wp-login.php 오류 메시지로 표시
- API 키 미설정 시 버튼 숨김 (깨진 버튼 노출 금지)

### 5. 개인정보 GitHub 업로드 절대 금지

커밋 전 반드시 `git diff --staged` 확인:
- API 키, Client Secret, 토큰이 포함되어 있지 않은가
- 사용자 이메일, 이름, 카카오 ID 등 개인정보가 포함되어 있지 않은가
- wp-config.php, .env 등 설정 파일이 포함되어 있지 않은가

`.gitignore` 필수 항목: `.env`, `*.log`, `/vendor/`, `wp-config.php`, `*-local.php`, `.DS_Store`, `node_modules/`, `*.zip`

---

## 플러그인별 절대 규칙

1. **FluentAuth 코어 파일 수정 금지** — `login_init`, `login_form` 훅만으로 구현
2. **세션/인증 처리 = WordPress 기본** — `wp_set_auth_cookie()` 외 커스텀 세션 금지
3. **FluentCRM은 선택** — FluentCRM 없어도 카카오 로그인 자체는 정상 동작해야 함
4. **발신자/이메일 처리 없음** — 이 플러그인은 로그인만 담당

---

## 파일 구조

```
fluent-auth-kakao/
├── fluent-auth-kakao.php        ← 메인 (의존성 체크, 자동업데이트)
├── includes/
│   ├── class-plugin.php         ← 부트스트랩 (싱글톤)
│   ├── class-kakao-handler.php  ← login_init + login_form 훅
│   ├── class-kakao-auth.php     ← OAuth HTTP 통신 (authorize→token→user/me)
│   └── class-settings.php       ← 관리자 설정 페이지
├── CLAUDE.md
└── PROJECT.md
```

---

## 카카오 API 엔드포인트

| 단계 | URL |
|------|-----|
| 인증 요청 | `https://kauth.kakao.com/oauth/authorize` |
| 토큰 발급 | `https://kauth.kakao.com/oauth/token` |
| 사용자 정보 | `https://kapi.kakao.com/v2/user/me` |

## FluentCRM 통합 원칙

- `defined('FLUENTCRM')` 체크 후 자동 등록 실행
- `FluentCrmApi('contacts')->createOrUpdate()` 공개 API만 사용
- 클래스 직접 인스턴스화 금지

## 테스트 체크리스트

- [ ] FluentAuth 없을 때 관리자 알림 표시, 버튼 미노출
- [ ] API 키 미설정 시 버튼 숨김
- [ ] 카카오 로그인 버튼 wp-login.php 표시 확인
- [ ] 신규 사용자: WP 계정 자동 생성 + FluentCRM 등록 확인
- [ ] 기존 사용자: 동일 이메일 계정 자동 연결 확인
- [ ] 이메일 없는 카카오 계정 → 가상 이메일 처리 확인
- [ ] state 파라미터 CSRF 방지 확인
- [ ] 설정 저장/불러오기 확인
