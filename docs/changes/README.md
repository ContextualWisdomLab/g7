# Change fragments

이 디렉터리는 아직 정식 릴리스에 포함되지 않은 변경의 고객 행동 중심 기록을 보관합니다.

- 파일명은 `YYYY-MM-DD-short-description.md` 형식을 사용합니다.
- 각 fragment는 `Added`, `Changed`, `Fixed`, `Security` 중 필요한 섹션만 포함합니다.
- PR이 병합되었다고 곧바로 릴리스된 것으로 간주하지 않습니다.
- 실제 버전을 올릴 때 검증이 끝난 fragment만 루트 `CHANGELOG.md`의 새 버전 섹션으로 옮기고, 옮긴 fragment는 삭제합니다.
- 설명은 무엇이 바뀌었는지보다 운영자·개발자·사용자가 다음에 무엇을 해야 하는지 알 수 있게 작성합니다.
