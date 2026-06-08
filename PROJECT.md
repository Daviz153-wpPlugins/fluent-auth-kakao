# Fluent Auth Kakao — Project Specification

## 개요

FluentAuth 소셜 로그인에 카카오 로그인 버튼을 추가하는 애드온 플러그인.  
회원제 커뮤니티 사이트 이용자의 로그인 편의성 향상이 핵심 목적.

- **플러그인명**: Fluent Auth Kakao
- **폴더**: `fluent-auth-kakao`
- **GitHub**: `Daviz153-wpPlugins/fluent-auth-kakao`
- **의존 플러그인**: FluentAuth (WPManageNinja)
- **배포 방식**: GitHub 릴리즈 + Plugin Update Checker 자동 업데이트

---

## 인터뷰 결과 요약

| 항목 | 결정 사항 |
|------|-----------|
| 사용 대상 | 본인 사이트 우선, 안정화 후 수강생 배포 |
| 핵심 문제 | 이메일 로그인 불편 — 사용자 비밀번호 분실 반복 |
| 가져올 정보 | 이메일 + 닉네임 (전화번호는 별도 심사 필요 → 추후 추가) |
| 기존 계정 처리 | 동일 이메일이면 기존 계정에 자동 연결 |
| 신규 사용자 | WP 계정 자동 생성 + FluentCRM 연락처 자동 등록 |
| 이메일 없는 경우 | 가상 이메일 자동 생성 (`kakao_{ID}@kakao.user`) |
| 닉네임 처리 | 카카오 닉네임을 WP 표시 이름으로 그대로 사용 |
| 설정 위치 | WP 관리자 메뉴 (FluentAuth 설정 옆) |
| WooCommerce 연동 | 제외 (게스트 결제 방식이라 불필요) |
| 전화번호 수집 | 제외 (별도 카카오 심사 필요, 추후 검토) |
| 버튼 디자인 | 카카오 공식 스타일 (노란 배경 #FEE500) |
| 코딩 철학 | FluentAuth 리소스 최대 활용, 커스텀 코드 최소화 |

---

## 핵심 기능 (DoD)

- [ ] 카카오 로그인 버튼이 wp-login.php 구글 버튼 아래 표시됨
- [ ] 버튼 클릭 → 카카오 OAuth → WP 로그인 완료
- [ ] 신규 사용자: WP 계정 자동 생성 (이메일 + 닉네임)
- [ ] 기존 사용자: 동일 이메일이면 기존 계정 자동 연결
- [ ] FluentCRM 연락처 자동 등록 (신규 가입 시)
- [ ] 이메일 없을 경우: `kakao_{ID}@kakao.user` 가상 이메일 생성
- [ ] 관리자 설정: REST API Key, Client Secret 저장
- [ ] FluentAuth 비활성화 시 이 플러그인도 차단 + 관리자 알림
- [ ] GitHub 자동 업데이트

---

## 카카오 OAuth 흐름

```
[카카오 로그인 버튼 클릭]
  wp-login.php?fak_auth=kakao
       ↓
kauth.kakao.com/oauth/authorize
  ?client_id={REST_API_KEY}
  &redirect_uri={wp-login.php}
  &response_type=code
  &state={nonce}
       ↓ 사용자 동의
wp-login.php?code=XXX&state=YYY  (콜백)
       ↓
POST kauth.kakao.com/oauth/token
  → access_token 획득
       ↓
GET kapi.kakao.com/v2/user/me
  → {id, kakao_account.email, kakao_account.profile.nickname}
       ↓
이메일로 기존 WP 계정 검색
  ├─ 있음: wp_set_auth_cookie() → 로그인
  └─ 없음: wp_insert_user() → 신규 생성 → FluentCRM 등록 → 로그인
```

---

## 파일 구조

```
fluent-auth-kakao/
├── fluent-auth-kakao.php        ← 메인 (의존성 체크, 자동업데이트)
├── includes/
│   ├── class-plugin.php         ← 부트스트랩 (싱글톤)
│   ├── class-kakao-handler.php  ← 훅 등록 (login_init, login_form)
│   ├── class-kakao-auth.php     ← OAuth 로직 (authorize→token→user/me)
│   └── class-settings.php       ← 관리자 설정 페이지
├── assets/
│   └── js/admin.js              ← 설정 페이지 (필요 시)
├── CLAUDE.md
├── PROJECT.md
└── .gitignore
```

---

## 설정 항목

| 설정 키 | 내용 | 필수 |
|---------|------|------|
| `fak_rest_api_key` | 카카오 앱 REST API 키 | ✅ |
| `fak_client_secret` | 카카오 앱 Client Secret | 권장 |

설정은 `fak_settings` wp_option 단일 키에 직렬화 저장.

---

## 작업 순서

| 단계 | 담당 | 내용 | 상태 |
|------|------|------|------|
| 1 | 완료 | 인터뷰 + 문서 + 전체 코드 + GitHub 레포 | ✅ 완료 |
| 2 | 사용자 | 카카오 개발자 콘솔 → 앱 생성, Redirect URI, REST API 키 발급 | ⏳ 대기 |
| 3 | 사용자 | WP 플러그인 업로드/활성화 → 설정에 API 키 입력 | ⏳ 대기 |
| 4 | 같이 | 집 Docker에서 통합 테스트 | ⏳ 대기 |
| 5 | 코드 | 버그 수정 + Plugin Update Checker 서브모듈 추가 | ⏳ 대기 |
| 6 | 코드 | v0.1.0 릴리즈 | ⏳ 대기 |

---

## 버전 계획

| 버전 | 내용 | 상태 |
|------|------|------|
| 0.1.0 | 카카오 로그인 + FluentCRM 자동 등록 | 코드 완료, 테스트 대기 |
| 0.2.0 | 전화번호 수집 (카카오 비즈니스 심사 통과 후) | 대기 |

---

## 카카오 앱 설정 가이드 (개발자용)

1. [카카오 개발자 콘솔](https://developers.kakao.com) → 애플리케이션 추가
2. **카카오 로그인** 활성화
3. **리다이렉트 URI 등록**: `https://도메인/wp-login.php`
4. **동의 항목**: 이메일(선택), 프로필 닉네임(필수)
5. **보안** → Client Secret 발급 (권장)
6. REST API 키를 플러그인 설정에 입력
