# Working in this repo

Convoro extension (`convoro-giveaways`). Not the Flarum 2 giveaways extension —
that is `ernestdefoe/giveaways`, a separate repo on its own version line. Check
which one a request means before touching anything: both are called "Giveaways".

## Standing rules

**Never open a pull request.** Commit to `master` and push. Ernest maintains
these repos alone; a PR is a step he has to close.

**Fix what is broken; don't ask first.** Report what was done. Confirm only for
things that are not fixes: publishing outward, destructive operations, anything
touching a live site's data.

**Version lives in `extension.json`.** The Marketplace reads it. Bump it in the
same commit as the change it describes.

## Releasing

Releases are a version commit on `master` plus a `vX.Y.Z` tag and a GitHub
release (see `v1.0.0`–`v1.2.0`).

🚨 **Cloud / web Claude Code sessions cannot tag or publish.** Both are refused
at the proxy:

- `git push origin <tag>` → `HTTP 403`
- `PATCH /repos/.../releases/<id>` → `403 {"message":"Creating, editing, or
  deleting releases is not permitted for this session type."}`

Branch pushes work fine; tags and releases never do. This is the session type,
not a missing tool or a permission that can be granted in-session. From a cloud
session: push the commit, then say plainly that the tag and release are
Ernest's to cut. Local sessions with `gh` and a PAT can do it directly.

## Memory

The standing rules across all of Ernest's work live in the private repo
`ernestdefoe/claude-memory` (`MEMORY.md` is the index). Local machines load it
via `bootstrap.sh`, which sets `autoMemoryDirectory` and a Stop hook that syncs
it. **Cloud sessions do not load it** unless it is attached as a source to the
environment — which is why this file exists.
