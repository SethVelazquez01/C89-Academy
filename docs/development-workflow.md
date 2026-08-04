# Development workflow

## Stable branch

The `main` branch must always contain tested and functional code.

Development work should not be performed directly on `main`.

## Branch names

Use short branches with one objective:

- `feat/name` for new functionality.
- `fix/name` for bug fixes.
- `test/name` for tests.
- `docs/name` for documentation.
- `refactor/name` for internal improvements.
- `chore/name` for configuration and maintenance.

## Working routine

1. Switch to `main`.
2. Pull the latest remote changes.
3. Create a new branch.
4. Make one focused change.
5. Run the relevant tests.
6. Review the diff and check for secrets.
7. Commit with a descriptive message.
8. Push the branch.
9. Open a Pull Request.
10. Review the Pull Request before merging.
11. Merge only when tests pass.
12. Delete the completed branch.

## Commit messages

Examples:

- `feat: add course enrollment`
- `fix: prevent disabled user login`
- `test: verify collaborator permissions`
- `docs: document local installation`
- `chore: configure application locale`

## Before merging

- Tests pass.
- The application builds when frontend files change.
- No `.env`, credentials or personal data are included.
- The diff contains only changes related to the task.
- Documentation is updated when necessary.