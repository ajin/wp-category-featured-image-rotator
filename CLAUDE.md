# Category Featured Image Rotator — project rules

WordPress plugin that assigns featured images to posts from category-specific
image pools. Targets WordPress 6.4+ and PHP 8.0+. Local dev runs in `wp-env`
(see README.md).

These rules are non-negotiable. Most are enforced by `npm test`; the rest are
reviewed by hand. If a rule blocks a feature, stop and ask, don't work around it.

## 1. Future-proof: public, stable WordPress APIs only

The plugin must keep working across WordPress upgrades without changes.

- Use only **public, documented** core APIs: functions, classes, hooks and
  filters listed in the [Developer Reference](https://developer.wordpress.org/reference/)
  that are not marked `@access private`, `@internal` or deprecated.
- **No private core functions.** Nothing prefixed with `_` (the i18n helpers
  `__()`, `_e()`, `_x()`, `_n()`, `_ex()`, `_nx()`, `_n_noop()`, `_nx_noop()` are fine).
- **No private core classes**: `WP_List_Table` and every `*_List_Table`,
  `_WP_Editors`, `WP_Internal_Pointers`.
- **No direct database access.** No `$wpdb`, no SQL. Use `WP_Query`,
  `get_posts()`, `get_terms()`, `get_post_meta()` / `update_post_meta()`,
  `get_term_meta()` / `update_term_meta()`, `get_option()` / `update_option()`,
  `set_post_thumbnail()`, etc.
- **No deprecated APIs** at or below WP 6.4. PHPCS flags them, and
  `WP_UnitTestCase` fails any test that triggers `_deprecated_*()` or
  `_doing_it_wrong()`.
- Don't rely on core internals: no reading or modifying core globals
  (`$wp_filter`, `$wp_query` internals, `$post` mutation), no hard-coded admin
  markup, CSS selectors or DOM structure, no copying core code.
- Hook in through documented actions/filters (e.g. `wp_after_insert_post`,
  `save_post_{$post_type}`, `{$taxonomy}_edit_form_fields`,
  `edited_{$taxonomy}`) and remove anything you add on deactivation/uninstall.
- No PHP features above 8.0 (enforced by PHPCompatibilityWP).
- No third-party PHP/JS runtime dependencies; only core-bundled scripts
  (`wp.media`, `jquery`, `wp-*` packages) loaded via `wp_enqueue_*`.

## 2. Security: no external calls, authenticated contexts only

- **No outbound network access of any kind.** No `wp_remote_*`, `download_url`,
  `media_sideload_image`, `fetch_feed`, `wp_mail`, cURL, sockets, `get_headers`,
  DNS lookups. No CDN assets, web fonts, telemetry, update checks or licence
  servers. Every asset ships in this repo.
- **Work only happens inside an authenticated admin action**, e.g. when a
  logged-in user saves a post or edits a category. Concretely:
  - No unauthenticated entry points: no `wp_ajax_nopriv_*`, `admin_post_nopriv_*`,
    REST routes, frontend form handlers or query-var handlers.
  - No background work: no WP-Cron (`wp_schedule_event` /
    `wp_schedule_single_event`), Action Scheduler or async requests.
  - Bulk/back-fill operations, if ever needed, run from an admin screen the
    user triggers, in batches, inside their request.
- Every write path checks **capability** (`current_user_can( 'edit_post', $id )`,
  `current_user_can( 'manage_categories' )`) and verifies a **nonce** on form
  submissions. Skip autosaves and revisions.
- Sanitize all input (`absint`, `sanitize_key`, `wp_unslash` + sanitizer) and
  escape all output (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`).
- Only attachment IDs that exist and are images (`wp_attachment_is_image()`)
  may be stored or assigned.
- No `eval`, `exec`/shell functions, `unserialize` (use `maybe_unserialize`)
  or `extract`.

## 3. Test after every change

**Run `npm test` after every code change and before reporting work as done.
Never report success while it is failing.** It runs, inside the wp-env container:

1. `npm run lint`: PHPCS with WordPress Coding Standards (security, deprecated
   APIs, i18n, prefixing) + PHPCompatibilityWP + hard-banned functions
   (`phpcs.xml.dist`).
2. `npm run check:boundaries`: static checks phpcs can't express: private
   core API, `$wpdb`, nopriv/REST/cron, hard-coded external URLs
   (`bin/check-boundaries.php`).
3. `npm run test:php`: PHPUnit integration tests against real WordPress
   (`tests/`). Uses the `wptests_` table prefix so the dev site is never touched.

A Claude Code hook (`.claude/settings.json` → `bin/claude-hook-test.sh`) runs
`npm test` after every Edit/Write to a code file and before ending a turn with
uncommitted code changes. Failures are fed back and must be fixed.

When adding behaviour:

- Add or update integration tests in `tests/integration/*Test.php`, extending
  `CategoryFeaturedImageRotator\Tests\TestCase`. It fails any test that makes
  an outbound HTTP request.
- Test as the real user would: log in with `$this->act_as( 'editor' )`, create
  posts/terms via `self::factory()`, and cover the denied path (wrong role,
  missing nonce, logged out) as well as the happy path.
- Never weaken a rule to make a test pass: no new `phpcs:ignore`, no exclusions
  in `phpcs.xml.dist`, no allow-listing in `check-boundaries.php` unless the
  user agrees. Any `phpcs:ignore` must name the sniff and give a reason.

## Conventions

- Prefix everything global with `cfir_` / `CFIR_`; namespace classes under
  `CategoryFeaturedImageRotator`. Text domain: `wp-category-featured-image-rotator`.
- WordPress Coding Standards formatting (tabs). `npm run lint:fix` auto-fixes.
- Composer runs in the container: `npm run composer -- <args>`.
- Before testing against the minimum supported version, set
  `"core": "WordPress/WordPress#6.4"` in `.wp-env.json` and run
  `npx wp-env start --update`.
