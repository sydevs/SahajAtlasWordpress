---
description: When a code comment earns its place, and which comments must never be deleted.
---

# Code comments

<!-- canonical:start — synced from claude-workflow/docs/code-comments.md. Edit there, not here. -->

- **Default to no comment.** Code shows *how*. A comment earns its place only by carrying *why* — a
  non-obvious constraint, a deliberate deviation, a gotcha, a workaround, or the reason a simpler
  version is wrong.
- **Never narrate the code.** No "loop over the users", no restating a name, a type or a signature,
  no `} // end if`.
- **Never narrate the change.** No "updated to", "as requested", "fixed the off-by-one". A comment
  must read correctly to someone who opens the file fresh and never saw the diff. Change context
  belongs in the commit message.
- **Never point at a moving target.** A spec section, a requirements doc, a design doc — all get
  superseded. Encode the substance instead. A ticket number, an RFC, a permalink, or a maintained
  doc at a stable path stays fine as a breadcrumb.
- **Apply the razor to every comment you keep, not only to the ones you cut.** "Carries a real
  *why*" and "is worded minimally" are separate judgements. A genuine *why* can still be three times
  too long. A five-line block rarely survives intact.
- **A one-line summary on a public function or endpoint is fine.** Restating a single clear line
  never is.
- **Never delete a tool directive, a `⚠` line, a cross-repo sync pointer, or a `#NNN` breadcrumb.**
  Directives change what a compiler, linter or formatter does. `⚠` is this workspace's own
  load-bearing marker. Nothing but prose enforces the couplings between these five repos.

TODOs are fine and need no issue ID. A TODO is a marker, not a substitute for the work.

<!-- canonical:end -->

## Carve-outs for this repo

⚠ **This repo's comments are its documentation.** `AGENTS.md` says to read the code and comments
first, and points at inline `⚠` comments for the full story behind each trap. The bar for deleting
anything here is far higher than in the TypeScript repos.

- **Never touch `sahaj-atlas.php` lines 1-20.** WordPress core parses that block to register the
  plugin. It looks exactly like a bloated file header.
- **The 18 `@package SahajAtlas` file headers** are a WordPress Coding Standards requirement.
- **The 9 `translators:` comments** in `includes/settings.php` and `includes/diagnostics.php` feed
  WP-CLI's i18n extractor, which reads the comment **immediately preceding** a gettext call and
  ships it into the `.pot`. This repo carries 34 `.po`/`.mo` pairs. Delete one, or insert anything
  between it and its call, and translator context drops for 34 locales.
- **`assets/atlas-page.css` line 4 is a cross-repo contract in a CSS comment.** That giving
  `<sahaj-atlas>` a height is the opt-in for a contained map (SahajAtlasWeb#170) lives in four files
  across three repos. CSS has no type system to carry it.

Nothing here catches a mistake for you. There is no formatter, no phpcs and no ESLint — `pnpm lint`
is a syntax check only.
