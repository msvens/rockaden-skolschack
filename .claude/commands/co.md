# Commit Command

Validate and commit changes:

## Steps

1. **Check for changes**
   - Run `git status` to see what's changed
   - If no changes, inform user and stop

2. **Run CI checks**
   - Run `pnpm run check` — PHPStan + phpcs, then translations
   - If any step fails: Stop and report errors

3. **Analyze changes for commit**
   - Run `git diff --staged` and `git diff` to understand changes
   - Run `git status` to see all modified and untracked files
   - Run `git log --oneline -5` to see recent commit style

4. **Stage changes**
   - **Default to ONE commit** with all changes (user often works on multiple things)
   - Only suggest splitting into multiple commits if changes are truly large AND distinct
   - **Ensure nothing is forgotten** - all modified files should be committed
   - For untracked files:
     - Include project-related files (new components, tests, configs)
     - **Ask about suspicious/unrelated files** (drafts, personal notes, random .txt files)
     - Never include sensitive files (.env, credentials, secrets)
   - Prefer staging specific files by name over `git add -A`

5. **Commit**
   - Generate clear, concise commit message
   - Use imperative mood ("Add feature" not "Added feature")
   - Focus on what and why, not how
   - **NO Claude attribution or co-authored-by lines**

6. **Report success**
   - Show commit hash
   - Show summary of what was committed

7. **Push (if requested)**
   - If arguments include "push", run `git push origin <current-branch>`
   - Confirm successful push

## Important

- **Abort immediately** if `pnpm run check` fails
- **Never** add co-authored-by or Claude attribution to commits
- **Never** commit sensitive files (.env, credentials, etc.)
- Only push when "push" is in arguments

## Example usage

```
/co              # Run CI checks and commit
/co push         # Run CI checks, commit, and push
```
