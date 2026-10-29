# Category Featured Image Rotator

[![Tests](https://github.com/ajin/wp-category-featured-image-rotator/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/ajin/wp-category-featured-image-rotator/actions/workflows/tests.yml)
[![Latest release](https://img.shields.io/github/v/release/ajin/wp-category-featured-image-rotator?sort=semver&label=release)](https://github.com/ajin/wp-category-featured-image-rotator/releases/latest)
[![Downloads](https://img.shields.io/github/downloads/ajin/wp-category-featured-image-rotator/total)](https://github.com/ajin/wp-category-featured-image-rotator/releases)
[![WordPress 6.4+](https://img.shields.io/badge/WordPress-6.4%2B-21759b?logo=wordpress&logoColor=white)](https://wordpress.org/download/)
[![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777bb4?logo=php&logoColor=white)](https://www.php.net/supported-versions.php)
[![License: GPL v2+](https://img.shields.io/badge/license-GPLv2%2B-blue)](LICENSE)

Automatically gives posts a featured image from images chosen for their category, taking turns so neighbouring posts don't repeat. The images are real WordPress featured images, so they stay even if the plugin is removed.

## Download and install

1. Download `wp-category-featured-image-rotator-<version>.zip` from the
   [latest release](https://github.com/ajin/wp-category-featured-image-rotator/releases/latest). Download the zip itself, not
   GitHub's "Source code" archives.
2. In WordPress, go to **Plugins → Add New Plugin → Upload Plugin**, choose
   the zip, then **Install Now** and **Activate**.
3. Go to **Posts → Featured Images**, click a category and add some images.

Each release is built and published automatically, and only after every
test passes (see [Releasing](#releasing-a-new-version)).

## Using the plugin

Everything is on one page, **Posts → Featured Images**. It doesn't change or
depend on any core WordPress screen, so core admin redesigns can't break it.

- **Category images** (anyone who can manage categories). It's laid out like
  Posts → Categories: every category in a list with its images and post
  count. Click a category (or **Edit images**) to open its edit screen. There
  you can add images from the Media Library (or upload new ones), drag them
  into order, remove them, and click **Update**. A **Next** badge shows which
  image the next post gets. Untick **Use these images for new posts** to pause
  a category, or tick **Start again from the first image**. A subcategory
  without images shows whose images it borrows.
- **Settings** (administrators): on/off, **Images in the post** (use the
  post's own first Media Library image, before its category's images; off by
  default), **On the post itself** (don't show added images at the top of the
  post's own page; they're still used in lists, search and sharing; off by
  default), image order (take turns or shuffle),
  default images, and the rules for posts in several categories.
- **Existing posts:** **Check existing posts** counts published posts
  without a featured image (in batches, so large sites don't time out). It
  says how many can be given one now and lets you see which. **Add featured
  images** then asks for confirmation and works through them oldest first,
  with progress and a Stop button.
- **Recently added:** the last assignments.

Images are added only when a post is **first published** (or scheduled). To
keep a post without an image, remove the image after publishing. Updates
never add one, so it stays removed.

### How it behaves

- **No public surface.** Visitors never reach plugin code. Admin code is
  loaded only in wp-admin, and admin-post/admin-ajax handlers exist only for
  logged-in users. There are no REST routes, shortcodes, front-end output or
  cron jobs, and nothing is added to REST responses. The only hook outside
  wp-admin is `wp_after_insert_post`, and it starts with a capability check.
  The one exception is opt-in: with **On the post itself** ticked, a display
  filter (`post_thumbnail_html` / `has_post_thumbnail`) runs on single-post
  pages. It only hides an image the plugin added.
- Images are assigned with `set_post_thumbnail()`, so they are ordinary
  featured images (`_thumbnail_id`) and stay after the plugin is deactivated
  or deleted. An existing featured image is never replaced.
- Assignment runs on `wp_after_insert_post`, so the block editor (REST),
  classic editor, XML-RPC and `wp_insert_post()` all work, but only for a
  logged-in user who can edit the post. Autosaves and revisions are skipped.
  There is no editor-specific code, so changes to the block editor's
  JavaScript APIs can't break it.
- **Scheduled posts** get their image when an editor schedules them
  (`future` counts as published). The image is in place when WordPress
  publishes the post, because the scheduled publish itself runs with no
  logged-in user.
- Rotation state is stored per pool in term meta and updated with a
  compare-and-swap (`update_term_meta()` with `$prev_value`). Two posts
  published at the same time can't get the same image, and a failed
  assignment doesn't use up a rotation slot. No custom table or `$wpdb` is
  needed.
- Removing or reordering images keeps the rotation going from the last image
  used. Deleted or non-image attachments are skipped.
- Uninstalling removes the plugin's options and term meta. It never
  removes featured images or Media Library files.

## Requirements

To run the plugin locally you need:

- **[Node.js](https://nodejs.org/en/download)** 20 or newer (ships with `npx`)
- **[Docker Desktop](https://www.docker.com/products/docker-desktop/)** —
  running, with the engine available to the current user.
  `@wordpress/env` uses Docker to spin up WordPress, MySQL and PHP
  containers. See the
  [Docker install guide](https://docs.docker.com/desktop/) and the
  [wp-env "Prerequisites" section](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/#prerequisites).
- **[Git](https://git-scm.com/downloads)**

Optional but useful:

- **[WP-CLI](https://wp-cli.org/#installing)** for inspecting the running
  site from your shell
- A code editor with PHP and HTML support (VS Code recommended)

The plugin itself targets:

- WordPress **6.4+**
- PHP **8.0+**

## Dependencies

The repo has a single development dependency, declared in [`package.json`](./package.json):

- **[`@wordpress/env`](https://www.npmjs.com/package/@wordpress/env)** —
  local WordPress dev environment powered by Docker.
  Full reference: the
  [official `@wordpress/env` documentation](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/).
  Configured via [`.wp-env.json`](./.wp-env.json), which mounts this
  folder as an active plugin on PHP 8.0 and enables `WP_DEBUG`,
  `WP_DEBUG_LOG` and `SCRIPT_DEBUG`.

There is no build step — PHP, CSS and JS are loaded directly from the
source tree.

## Getting started

1. Make sure **Docker Desktop is running**.
2. From the repo root, install the dev dependency:

   ```bash
   npm install
   ```

3. Start a clean WordPress instance with the plugin mounted and activated:

   ```bash
   npx wp-env start
   ```

   On first run this downloads WordPress core, MySQL, PHP and the WP-CLI
   container, then boots the stack. Subsequent starts are fast.

4. Open the site:

   - Frontend: <http://localhost:8888>
   - Admin: <http://localhost:8888/wp-admin>
     (default user `admin`, password `password`)

## Testing

Project rules (stable public WordPress APIs only, no external calls,
authenticated contexts only) are documented in [`CLAUDE.md`](./CLAUDE.md) and
enforced by the test suite. Run it after every change:

```bash
npm test
```

It runs inside the wp-env container (so no local PHP or Composer is needed)
and requires the environment to be running. On a fresh clone, install the PHP
dev tools first:

```bash
npm run composer -- install
```

| Command | What it does |
| --- | --- |
| `npm run lint` | PHPCS: WordPress Coding Standards, security sniffs, deprecated APIs (WP 6.4+), PHP 8.0+ compatibility, banned functions |
| `npm run lint:fix` | Auto-fix formatting issues |
| `npm run check:boundaries` | Static checks for private core APIs, `$wpdb`, nopriv/REST/cron entry points, external URLs |
| `npm run test:php` | PHPUnit integration tests against real WordPress; fails on any outbound HTTP request or deprecation notice |
| `npm run test:php:multisite` | The same tests on a multisite network |
| `npm run build` | Build the installable zip into `dist/` (only the files the plugin needs) |

PHPUnit uses a separate `wptests_` table prefix, so running tests never wipes
your local dev site.

## Continuous integration

Every push to `main` and every pull request runs
[`.github/workflows/tests.yml`](https://github.com/ajin/wp-category-featured-image-rotator/actions/workflows/tests.yml), which
sets the **Tests** badge above:

| Job | Checks |
| --- | --- |
| WP 6.4 · PHP 8.0 | The oldest supported versions |
| WP latest · PHP 8.3 | The current release |
| WP trunk · PHP 8.4 | The next, unreleased WordPress, to catch breakage early |
| Build and check the plugin zip | Builds the zip, installs it into a clean WordPress, checks it loads with no PHP errors, and keeps it as a downloadable artifact for 30 days |

Each WordPress job runs coding standards, the security boundary checks and
PHPUnit on both a single site and a multisite network.

## Releasing a new version

1. Set the version everywhere it appears:

   ```bash
   bin/set-version.sh 0.2.0
   ```

2. Add a `## 0.2.0` section to `CHANGELOG.md`; it becomes the release notes.
3. Commit and push to `main`, then tag the release:

   ```bash
   git tag v0.2.0
   ```

   ```bash
   git push origin v0.2.0
   ```

Pushing the tag runs [`.github/workflows/release.yml`](https://github.com/ajin/wp-category-featured-image-rotator/actions/workflows/release.yml).
It runs the full test suite and checks that the tag matches the plugin's
version. If both pass, it publishes a
[GitHub release](https://github.com/ajin/wp-category-featured-image-rotator/releases) with the plugin zip and its SHA-256 checksum.
If anything fails, nothing is published. A version with a suffix such as
`1.0.0-beta.1` is marked as a pre-release.

## Common commands

All commands are run from the repo root. Full command reference:
[`@wordpress/env` CLI docs](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/#command-reference).

```bash
# Start / stop the local environment
npx wp-env start
npx wp-env stop

# Apply changes to .wp-env.json or after pulling new code
npx wp-env start --update

# Reset the database back to a fresh install
npx wp-env clean all
npx wp-env start

# Tail PHP / WordPress logs
npx wp-env logs

# Run any WP-CLI command inside the container
npx wp-env run cli wp plugin list
npx wp-env run cli wp option get siteurl
npx wp-env run cli wp cache flush
```

Shortcuts are also available as npm scripts: `npm run env:start`,
`env:stop`, `env:update`, `env:clean`, `env:logs`, and
`npm run wp -- <command>` (e.g. `npm run wp -- plugin list`).
