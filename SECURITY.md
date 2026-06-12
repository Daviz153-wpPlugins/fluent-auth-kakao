# Security Policy

## 공급망 위협 대응 (릴리즈 절차)

이 플러그인은 Plugin Update Checker를 통해 GitHub 릴리즈에서 자동 업데이트됩니다.
GitHub 계정 탈취 → 악성 릴리즈 배포가 가장 현실적인 공급망 공격 경로입니다.

### 릴리즈 전 필수 체크리스트

- [ ] GitHub 계정 2FA 활성화 상태 확인
- [ ] `git diff HEAD~1` 로 변경 파일 직접 검토
- [ ] `git diff --staged` 로 개인정보/API 키 미포함 확인
- [ ] 태그는 반드시 서명된 커밋에서만 생성: `git tag -s vX.Y.Z`
- [ ] 릴리즈 에셋 zip에 `.env`, `*.log`, `wp-config.php` 미포함 확인

### GitHub 저장소 보안 설정

- Branch protection: `main` 브랜치에 직접 push 금지, PR 필수
- Tag protection: `v*` 태그는 소유자만 생성 가능
- Dependency review: 서브모듈(`plugin-update-checker`) 업데이트 시 변경 diff 검토

## 취약점 신고

보안 취약점 발견 시 GitHub Issues가 아닌 이메일로 비공개 신고:
tearstar153@gmail.com

공개 이슈 트래커에 취약점 세부 내용을 먼저 올리지 마세요.
