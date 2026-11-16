# Releasing, staged rollout and rollback

Merging a version bump to `main` is the release (`.github/workflows/release.yml`). This page covers
what happens to the 13 sites after that, and how to undo a release that turns out bad.

## Staged rollout

Each site asks GitHub for the newest release about every 12 hours. `includes/updates.php` filters
that list before WordPress sees it:

- A **canary** site is offered a release as soon as it is published.
- Every other site is offered a release once it has been published for the **hold**, 3 days by
  default. Until then it is offered nothing, or the newest older release it does not already run.
- Doubt always means "offer it". A release with no readable publish date, a release dated in the
  future, a hold that is not a number, and any error in this code all fall back to today's
  behaviour. Nothing here stops anyone updating by hand: download the zip from the release and
  upload it, or run `wp plugin update`.

The hold is counted from the release's `published_at`, so it ends at the next check after the
three days, up to 12 hours later.

### Settings, all in `wp-config.php`, none on a screen

| Constant | Default | Meaning |
| --- | --- | --- |
| `SAHAJ_ATLAS_CANARY` | not set (not a canary) | `define( 'SAHAJ_ATLAS_CANARY', true );` takes releases at once. Choose one or two low-traffic sites. |
| `SAHAJ_ATLAS_UPDATE_HOLD_DAYS` | `3` | Days a non-canary waits. `0` switches the hold off for that site. A fraction is fine. |

Both pass through a filter (`sahaj_atlas_is_canary`, `sahaj_atlas_update_hold_days`) for a site
that runs an mu-plugin instead. The diagnostics panel does not show the state: it checks the
Atlas page, and a release that is on its way is not a page defect.

### Skipping the hold for a fix that cannot wait

After the release is published, edit its notes on GitHub and put `[skip-hold]` on a line by
itself:

```
Fixes a security problem in the sitemap.

[skip-hold]
```

Every site offers it at its next check. The marker is read from the release, so it can be added
after publishing, within the 3 days. A mention inside a sentence does not count.

### Sites that run an older release

The staging code ships inside the plugin. A site learns it only by updating to the first release
that contains it, and that release reaches every site at once, as releases did before it. The
rule applies from the release after that.

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
4. **Merge the PR.** The release publishes itself.
5. **Add `[skip-hold]` to the new release's notes**, so the sites that already took the bad
   release are offered the fix at once rather than after the hold.

Sites that never took the bad release are offered 0.3.6 and nothing else.

### One site, by hand

A site that cannot wait for the fix can go back to the previous version itself: download
`sahaj-atlas-<version>.zip` from that release, then on **Plugins > Add New > Upload Plugin**
choose "Replace current with uploaded". With WP-CLI:
`wp plugin install <zip-url> --force`. Its settings are kept. The site is then offered the bad
release again at its next check once the hold ends (at once, on a canary), so turn off automatic
updates for the plugin there until the fix is out, or set `SAHAJ_ATLAS_UPDATE_HOLD_DAYS` high.
