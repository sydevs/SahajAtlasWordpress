# Releasing: rollback

Merging a version bump to `main` is the release (`.github/workflows/release.yml`). Every site
that runs the plugin is offered it at its next update check, about every 12 hours. There is no
hold and no staging in the plugin: whatever is published reaches all 13 sites. So the only gate
is CI before the merge, which boots the zip it builds (`pnpm test:zip`, `tests/zip.mjs`), and the
only undo is a newer release.

## Rolling back a bad release

WordPress never offers a lower version, so the way back is a **newer** release that carries the
old code.

1. **Stop new sites picking it up.** Convert the bad release to a draft:
   `gh release edit v0.3.5 --draft`. A draft is never offered, and
   `releases/latest/download/sahaj-atlas.zip` goes back to the previous release. The tag stays, so
   the next version must still be higher than the bad one.
2. **Prepare the fix.** Branch from `main`. Either revert the bad change
   (`git revert <commit>`, leaving the `chore(release)` bump in place), or restore the previous
   release's files without its workflows:
   `git restore --source=v0.3.4 --staged --worktree -- . ':(exclude).github'`, then delete files
   `git diff --stat v0.3.4` shows were added since.
3. **Bump the version** the way any release does, to one above the bad release (0.3.6), in
   `sahaj-atlas.php` (twice), `blocks/embed/block.json`, and `readme.txt` (Stable tag and a
   changelog entry). The PR's package check tells you which one was missed.
4. **Merge the PR** once CI is green. The release publishes itself, and every
   site, including those that took the bad release, is offered it at its next check.

Sites that never took the bad release are offered 0.3.6 and nothing else.

### One site, by hand

A site that cannot wait for the fix can go back to the previous version itself: download
`sahaj-atlas-<version>.zip` from that release, then on **Plugins > Add New > Upload Plugin**
choose "Replace current with uploaded". With WP-CLI:
`wp plugin install <zip-url> --force`. Its settings are kept.

Do this only once step 1 has drafted the bad release. A draft is not the latest release, so the
downgraded site is offered nothing, and it takes 0.3.6 by itself once that publishes. Leave
automatic updates on: they are the plugin's only way back onto a site nobody here controls. While
the bad release is still published, a downgraded site is offered it again within 12 hours.
