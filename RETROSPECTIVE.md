# 개발 회고 — Fluent Auth Kakao

> 작성일: 2026-06-13 | 버전: 0.1.6  
> 목적: 다음 플러그인 작업 시 같은 시행착오를 반복하지 않기 위한 참고 문서

---

## 1. 핵심 교훈 — 한 줄 요약

> **기존 구현(Google 로그인)을 먼저 정밀 분석했다면 후공정의 80%가 없었다.**

---

## 2. 무엇이 문제였나

### 원인

FluentAuth가 이미 Google/Github/Facebook 소셜 로그인을 구현해둔 상태였다.  
카카오 로그인도 그 위에 얹는 구조인데, **Google 구현을 참조하지 않고 독립적으로 설계**하여  
나중에 기능 패리티를 맞추는 후공정이 대거 발생했다.

### 후공정 발생 항목 (나중에 추가된 것들)

| 항목 | 초기 구현 | 문제 | 수정 내용 |
|------|-----------|------|-----------|
| `redirect_to` 보존 | `$_REQUEST['redirect_to']` 직접 읽기 | OAuth 라운드트립 후 `$_REQUEST`가 비워짐 | `fak_intent_redirect` 쿠키로 보존 |
| 2FA 게이트 | 없음 (우회된다고 잘못 판단) | 2FA 설정 사용자도 카카오로 우회 로그인 가능 | `TwoFaHandler::sendAndGet2FaConfirmFormUrl` 추가 |
| 역할 기반 리다이렉트 | `admin_url()` 고정 | subscriber가 관리자 화면으로 이동 | `edit_posts`/`read` 권한 체크 + 멀티사이트 로직 |
| `fluent_auth/social_redirect_to` 필터 | 없음 | FluentAuth의 리다이렉트 커스터마이징 훅 무시 | 버튼 렌더링 시 필터 적용 |
| 로그인 사용자 불일치 가드 | 없음 | 이미 로그인된 상태에서 다른 카카오 계정으로 덮어쓰기 가능 | `is_user_logged_in()` + ID 비교 추가 |
| 훅 우선순위 충돌 | `login_init` priority 10 | Google ?code&state 콜백을 FluentAuth가 먼저 가로채 충돌 | `priority 0` + `fak_` 쿠키 유무로 분기 |

### 2FA 초기 분석 오류

"소셜 로그인은 2FA를 우회한다"고 잘못 파악했다가 코드를 직접 읽고 수정.  
→ **가정하지 말 것. 제3자 코드는 반드시 직접 읽고 확인.**

---

## 3. 다음 플러그인 개발 SOP

FluentAuth 기반 애드온을 개발할 때 이 순서를 따른다.

### Step 0 — 기존 구현 분석 (착수 전 필수)

```bash
# 유사 기능의 기존 코드 먼저 전체 읽기
docker exec wordpress-dev-wordpress-1 cat \
  /var/www/html/wp-content/plugins/fluent-security/app/Hooks/Handlers/SocialAuthHandler.php
```

분석 체크리스트:
- [ ] 훅 이름과 우선순위 (`add_action` 전체 목록)
- [ ] 쿠키/세션 사용 패턴 (key 이름, TTL, httponly 등)
- [ ] 에러 처리 방식 (WP_Error, 리다이렉트, 필터)
- [ ] 리다이렉트 로직 (역할/멀티사이트 분기)
- [ ] 외부 서비스 의존성 (2FA, FluentCRM 등 조건부 체크 방식)
- [ ] 공개 필터/액션 훅 (3rd-party가 확장할 수 있는 지점)

### Step 1 — 작업 플랜 수립 (분석 완료 후)

- 기존 구현과 내가 구현할 기능의 **차이점만** 정리
- 재사용 가능한 것, 훅만으로 해결되는 것, 직접 구현해야 하는 것 분류
- 보안 리스크 항목을 별도로 체크리스트로 작성

### Step 2 — 구현

- CLAUDE.md 5대 원칙 준수
- 기존 구현과 다른 결정을 내릴 때는 이유를 주석으로 남길 것
- 어드바이저 호출 시점: **착수 전**(설계 검토), **완료 전**(구현 검토)

### Step 3 — 검증 (Docker exec)

```bash
# 1. PHP 문법 검사 (cp 후 반드시 sleep 1 먼저)
cp [src] [docker-volume-path] && sleep 1 && docker exec ... php -l [file]

# 2. 플러그인 상태
docker exec ... wp --allow-root plugin status [plugin-slug]

# 3. 핵심 클래스/메서드 존재 확인
docker exec ... wp --allow-root eval 'echo class_exists("ClassName") ? "OK" : "FAIL";'
```

### Step 4 — 테스트 (카카오 앱 연동 후)

RETROSPECTIVE.md 5장의 테스트 체크리스트 순서대로 진행.

---

## 4. 운영 패턴 (Docker 환경)

### 파일 싱크

```bash
# 로컬 → Docker 볼륨 동기화
cp /Users/daviz/Projects/Wp-Plugin/fluent-auth-kakao/[file] \
   /Users/daviz/Projects/wordpress-dev/plugins/fluent-auth-kakao/[file]

# Docker 볼륨 flush 딜레이 — cp 직후 php -l 하면 거짓 파싱 오류 발생
sleep 1
docker exec wordpress-dev-wordpress-1 php -l /var/www/html/wp-content/plugins/[file]
```

> Docker 볼륨에 파일이 완전히 반영되기까지 약 1초 지연이 있다.  
> `sleep 1` 없이 `php -l`을 실행하면 "Unclosed '{'" 같은 거짓 오류가 출력된다.

### wp eval 사용 원칙

- 부작용 있는 함수(2FA 코드 발송, DB 쓰기, 이메일 발송)는 **Reflection으로 isEnabled만 검증**
- 테스트 유저를 따로 만들고 테스트 후 삭제
- 절대 관리자 계정으로 부작용 있는 wp eval 실행 금지

---

## 5. 테스트 현황

### 완료된 테스트

| 테스트 | 방법 | 결과 |
|--------|------|------|
| PHP 문법 검사 | `php -l` | ✅ 오류 없음 |
| 플러그인 로드/활성화 | `wp plugin status` | ✅ Active |
| TwoFaHandler 클래스 존재 | `class_exists()` | ✅ |
| 2FA `isEnabled` 로직 | Reflection + `wp eval` | ✅ email2fa=yes 시 true 반환 |
| 로그인 페이지 버튼 렌더링 | HTML 덤프 확인 | ✅ `#FEE500` 버튼 표시 |
| FluentAuth 미설치 시 차단 | 플러그인 비활성화 후 확인 | ✅ 관리자 알림 표시 |

### 미완료 테스트 — 카카오 앱 설정 후 진행

**기본 플로우**

- [ ] 신규 사용자: 카카오 로그인 → WP 계정 자동 생성
- [ ] 신규 사용자: FluentCRM 연락처 자동 등록
- [ ] 기존 사용자: 동일 이메일 → 기존 계정 자동 연결
- [ ] 기존 사용자: `fak_kakao_id` 메타로 연결 (이메일 변경 시에도 유지 확인)
- [ ] 이메일 없는 카카오 계정 → 가상 이메일 생성 후 로그인
- [ ] 설정 저장/불러오기 (REST API GET/POST)
- [ ] API 키 미설정 시 버튼 숨김 확인

**보안**

- [ ] 잘못된 state → `csrf_fail` 오류
- [ ] state 재사용 (같은 state 두 번) → 두 번째 요청 실패
- [ ] 쿠키 없는 콜백 → FluentAuth로 위임(카카오 핸들러 개입 없음)
- [ ] Rate limit: 60초 내 10회 초과 → `rate_limit` 오류

**리다이렉트**

- [ ] `redirect_to` 파라미터 지정 시 로그인 후 해당 URL로 이동
- [ ] subscriber 역할 → `profile.php`로 이동
- [ ] editor/admin 역할 → `wp-admin/`으로 이동
- [ ] 2FA 활성화 사용자 → 2FA 확인 화면 표시

**예외 처리**

- [ ] 이미 로그인된 상태에서 다른 카카오 계정 로그인 시도 → `email_mismatch` 오류
- [ ] 신규가입 비활성화 상태에서 신규 카카오 계정 로그인 → `signup_disabled` 오류
- [ ] 카카오 API 서버 오류(500) → `token_fail` 또는 `userinfo_fail` 오류

---

## 6. 보안 우선순위

이 플러그인에서 보안은 기능보다 우선한다. 구현 중 기능과 보안이 충돌하면 보안을 택한다.

### 완료된 보안 조치

| 조치 | 구현 위치 |
|------|-----------|
| CSRF 방지 (state transient + 쿠키 이중 검증) | `handleLoginInit()` |
| state 일회성 사용 (검증 즉시 transient 삭제) | `handleLoginInit()` |
| Rate limit — REMOTE_ADDR 전용 (HTTP 헤더 스푸핑 방지) | `isRateLimited()` + `getClientIp()` |
| `wp_remote_post` 타임아웃 15초 명시 | `getToken()`, `getUserInfo()` |
| Kakao API `id` — `(int)` 캐스트 + `empty()` 체크 | `getUserInfo()` |
| `wp_safe_redirect` — 외부 도메인·`//evil.com` 차단 확인 | `processCallback()` |
| `virtualEmail` 충돌 확률 96비트, 100만 사용자 기준 ~10⁻¹⁷ (허용) | `virtualEmail()` |
| 모든 API 응답 `sanitize_*()` 처리 | `class-kakao-auth.php` |
| 모든 출력 `esc_*()` 처리 | `buttonHtml()` |
| API 키 DB 저장 (하드코딩 금지) | `class-settings.php` |
| `manage_options` 권한 체크 + wp_config 방식 키 REST 미노출 | `class-settings.php` |
| 로그인 사용자 계정 불일치 차단 | `processCallback()` |
| 2FA 게이트 (기존 사용자) | `processCallback()` |
| Lax SameSite + httponly 쿠키 | `handleLoginInit()` |
| FluentCRM 예외 격리 — 선택 의존성 예외가 로그인 중단하지 않도록 | `registerToFluentCrm()` |

### 코딩 품질 개선 완료

| 항목 | 내용 |
|------|------|
| 데드 코드 제거 | `isWpConfigMethod()` 삭제 (미사용 메서드) |
| 전역 변수 오염 방지 | `$fakUpdater` → 메서드 체이닝으로 대체 |
| FluentCRM `user_id` 연결 | `createOrUpdate()`에 `user_id` 필드 추가 |
| FluentCRM 중복 체크 제거 | `getContact()` 사전 조회 제거 (upsert가 이미 처리) |

---

## 7. 코드 품질 원칙 (이번 작업에서 확인된 것)

**심플하게 유지하는 기준:**
- 동일 로직 3회 이상 반복 시에만 추상화
- 미래 기능을 위한 인터페이스/추상클래스 금지
- 에러 메시지 상수화: 수정 시 한 곳만 찾으면 됨 (`ERROR_MESSAGES`)

**오류 찾기 쉽게 하는 기준:**
- 각 OAuth 단계(token, userinfo)를 별도 `WP_Error`로 반환
- 에러 코드에 의미 있는 이름 (`csrf_fail`, `token_fail`)
- 조용한 실패 금지: `return;` 전에 반드시 `loginError()` 호출

**유지보수 쉽게 하는 기준:**
- 클래스 하나 = 역할 하나 유지 (Handler / Auth / Settings 분리)
- 외부 의존성은 항상 `class_exists()` / `defined()` 후 사용
- 쿠키 key 이름은 상수로 중앙관리 (`INTENT_COOKIE_KEY`, `STATE_KEY`)
