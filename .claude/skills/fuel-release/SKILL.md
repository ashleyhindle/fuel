---
name: fuel-release
description: Create a new release for fuel. Use when asked to "release", "make a release", "tag a release", or "publish a new version".
---

# Fuel Release

Create and publish a new release for fuel.

## When to Use
Invoke this skill when asked to create a new release, tag a new version, or publish.

## Workflow

### 1. Sync Tags from Remote
```bash
git fetch --tags
```

### 2. Determine Current Version
```bash
git tag --sort=-v:refname | head -1
```
This gives you the latest tag (e.g., `0.6.4`).

### 3. Calculate Next Version

Review the changes and decide the version bump:

**Patch** (0.6.4 -> 0.6.5) - Default for most releases:
- Bug fixes
- Small improvements
- Minor tweaks
- Documentation updates

**Minor** (0.6.4 -> 0.7.0) - New functionality:
- New features or commands
- Significant new capabilities
- Notable workflow improvements
- Multiple related enhancements

**Major** (0.6.4 -> 1.0.0) - Breaking or milestone:
- Breaking changes to CLI or config
- Major architectural changes
- Reaching a significant milestone

If unsure, prefer patch. User can specify `minor` or `major` explicitly.

### 4. Review Recent Changes
```bash
git log $(git tag --sort=-v:refname | head -1)..HEAD --oneline
```
Review what's being released to craft an appropriate title.

### 5. Create a Creative Title
The release title format is: `<version> - <creative phrase>`

The creative phrase should be 3-5 words that:
- Summarizes the main theme of changes
- Is fun/memorable (alliterative, rhyming, punny, or just catchy)
- Starts with something related to the changes

Examples:
- `0.6.5 - smoother status streaming` (alliterative)
- `0.6.6 - dependencies done right` (alliterative)
- `0.7.0 - browser brings beauty` (alliterative)
- `1.0.0 - finally feels finished` (alliterative)
- `0.6.7 - tasks track true` (alliterative)
- `0.5.4 - bugs be gone` (playful)
- `0.8.0 - epic endings everywhere` (alliterative, about epics feature)

### 6. Create and Push the Tag
```bash
git tag <version>
git push origin <version>
```

### 7. Create GitHub Release
```bash
gh release create <version> --title "<version> - <creative phrase>" --generate-notes
```

The `--generate-notes` flag auto-generates release notes from commits since the last tag.

### 8. Confirm Success
```bash
gh release view <version>
```

### 9. Wait for Binaries
GitHub Actions builds binaries for all platforms. Poll until assets appear:
```bash
gh release view <version> --json assets --jq '.assets[].name'
```

Expected assets (4 binaries):
- `fuel-darwin-arm64`
- `fuel-darwin-x64`
- `fuel-linux-arm64`
- `fuel-linux-x64`

If empty, wait ~1-2 minutes and check again. Once all 4 appear, notify the user that binaries are ready for download.

## Example Full Run

```bash
# Sync
git fetch --tags

# Check current
git tag --sort=-v:refname | head -1
# Output: 0.6.4

# See what changed
git log 0.6.4..HEAD --oneline
# Output:
# abc1234 fix: show reconnecting status when runner drops
# def5678 fix: trim status lines in consume command

# Create tag
git tag 0.6.5
git push origin 0.6.5

# Create release with fun title about the status/streaming fixes
gh release create 0.6.5 --title "0.6.5 - streaming stays stable" --generate-notes

# Verify
gh release view 0.6.5

# Check for binaries (poll until 4 assets appear)
gh release view 0.6.5 --json assets --jq '.assets[].name'
# Output when ready:
# fuel-darwin-arm64
# fuel-darwin-x64
# fuel-linux-arm64
# fuel-linux-x64
```

## Notes
- Always fetch tags first to ensure you have the latest
- The version number has no `v` prefix (use `0.6.5` not `v0.6.5`)
- Keep the creative phrase lowercase for consistency
- If there are no commits since the last tag, inform the user there's nothing to release
