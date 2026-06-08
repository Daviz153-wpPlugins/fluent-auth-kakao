# CLAUDE.md — Fluent Auth Kakao

## 절대 규칙

1. **FluentAuth 코어 파일 수정 금지** — 훅/필터만으로 구현
2. **최소 코드 원칙** — FluentAuth가 이미 하는 것을 중복 구현하지 않는다
3. **보안 우선** — nonce 검증, state 파라미터(CSRF), sanitize/escape 철저히
4. **커스텀 DB 테이블 없음** — wp_options + wp_usermeta만 사용
5. **GitHub 업로드 금지 항목**: API 키, Client Secret, 개인정보, .env 파일

## 보안 체크리스트

모든 커밋 전 확인:
- [ ] `check_ajax_referer()` 또는 nonce 검증 있는가
- [ ] OAuth state 파라미터로 CSRF 방지하는가
- [ ] 외부 API 응답값 전부 sanitize 처리하는가
- [ ] 사용자 입력값 전부 sanitize/escape하는가
- [ ] API 키/시크릿이 코드에 하드코딩되지 않았는가

## FluentAuth 통합 원칙

- 사용자 로그인/생성은 가능하면 FluentAuth의 `AuthService` 활용
- FluentAuth가 없으면 플러그인 비활성화
- 버튼 HTML 스타일은 FluentAuth 기존 소셜 버튼과 동일 패턴 유지

## FluentCRM 통합 원칙

- FluentCRM 있을 때만 자동 등록 실행 (`defined('FLUENTCRM')` 체크)
- FluentCRM 없어도 카카오 로그인 자체는 정상 동작해야 함

## 코드 스타일

- PHP 8.2 문법 사용 가능 (match, named args, enum 등)
- namespace: `FluentAuthKakao`
- 상수 prefix: `FAK_`
- wp_option 키 prefix: `fak_`
- 모든 클래스 파일: `class-{name}.php` 형식

## 파일 구조

```
fluent-auth-kakao/
├── fluent-auth-kakao.php        ← 진입점, 의존성 체크
├── includes/
│   ├── class-plugin.php         ← 부트스트랩
│   ├── class-kakao-handler.php  ← login_init + login_form 훅
│   ├── class-kakao-auth.php     ← OAuth HTTP 통신
│   └── class-settings.php       ← 설정 페이지
├── assets/js/admin.js
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

## 테스트 체크리스트

- [ ] FluentAuth 없을 때 차단 메시지 표시
- [ ] 카카오 로그인 버튼 표시 확인
- [ ] 신규 사용자: WP 계정 자동 생성 확인
- [ ] 기존 사용자: 동일 이메일 계정 연결 확인
- [ ] FluentCRM 연락처 자동 등록 확인
- [ ] 이메일 없는 카카오 계정 처리 확인
- [ ] state 파라미터 CSRF 방지 확인
- [ ] 설정 저장/불러오기 확인
