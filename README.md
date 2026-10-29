# Category Featured Image Rotator

[![Tests](https://github.com/ajin/wp-category-featured-image-rotator/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/ajin/wp-category-featured-image-rotator/actions/workflows/tests.yml)
[![Latest release](https://img.shields.io/github/v/release/ajin/wp-category-featured-image-rotator?sort=semver&label=release)](https://github.com/ajin/wp-category-featured-image-rotator/releases/latest)
[![Downloads](https://img.shields.io/github/downloads/ajin/wp-category-featured-image-rotator/total)](https://github.com/ajin/wp-category-featured-image-rotator/releases)
[![WordPress 6.4+](https://img.shields.io/badge/WordPress-6.4%2B-21759b?logo=wordpress&logoColor=white)](https://wordpress.org/download/)
[![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777bb4?logo=php&logoColor=white)](https://www.php.net/supported-versions.php)
[![License: GPL v2+](https://img.shields.io/badge/license-GPLv2%2B-blue)](LICENSE)

Automatically adds a featured image to new posts, using images you've picked for each category. The images take turns, so your posts don't all end up with the same one. They're regular WordPress featured images, so they stay even if you remove the plugin.

## Install

1. Download `wp-category-featured-image-rotator-<version>.zip` from the
   [latest release](https://github.com/ajin/wp-category-featured-image-rotator/releases/latest).
   Get the plugin zip, not GitHub's "Source code" downloads.
2. In WordPress, go to Plugins → Add New Plugin → Upload Plugin, choose the
   zip, and activate it.

## How to use it

Go to Posts → Featured Images.

1. Click a category, add a few images, and click Update.
2. Publish a post in that category without a featured image. It gets the
   first image, the next post gets the second, and so on.

Some details:

- Posts that already have a featured image are never changed.
- If you don't want an image on a particular post, remove it after
  publishing. It won't be added again.
- To add images to older posts, use Existing posts at the bottom of the page.
- In Settings, on the same page, you can shuffle the order, set default
  images, use a photo that's already in the post, or hide the added image on
  the post's own page.

## How it works

- The plugin only runs for logged-in users who can edit the post. Nothing
  runs for visitors unless you turn on "Don't show the featured image on the
  post", which adds a small display filter on single post pages.
- Images are set with `set_post_thumbnail()`, the same as choosing a
  featured image by hand, so they work with any theme, SEO plugin or social
  sharing tool, and stay after the plugin is deactivated or deleted.
- An image is added when a post is first published or scheduled, from the
  block editor, the classic editor, the REST API, XML-RPC or code. Updating
  a post never adds one.
- Each category's position in its image list is stored in term meta and
  updated with a compare-and-swap (`update_term_meta()` with `$prev_value`),
  so two posts published at the same moment never get the same image. No
  custom database table is used.
- If you remove or reorder images, the order carries on from the last image
  used. Deleted images are skipped.
- Uninstalling removes the plugin's own settings. Featured images and Media
  Library files are left alone.

## Development

### Requirements

- [Node.js](https://nodejs.org/en/download) 20 or newer
- [Docker Desktop](https://www.docker.com/products/docker-desktop/), running.
  The local site uses [`@wordpress/env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/),
  which runs WordPress, MySQL and PHP in Docker.
- [Git](https://git-scm.com/downloads)
- [WP-CLI](https://wp-cli.org/#installing) is handy but not required.

The plugin itself needs WordPress 6.4 or newer and PHP 8.0 or newer.

### Local setup

With Docker running, from the repo folder:

```bash
npm install
```

```bash
npx wp-env start
```

The first start downloads WordPress and the containers, so it takes a few
minutes. After that it's quick. The site runs at <http://localhost:8888>
and the admin at <http://localhost:8888/wp-admin> (user `admin`, password
`password`). The plugin is already activated. Settings for the local site
are in [`.wp-env.json`](./.wp-env.json).

### Tests

The project rules (public WordPress APIs only, no outside connections,
logged-in users only) are written down in [`CLAUDE.md`](./CLAUDE.md), and
the tests check them. Install the PHP tools once:

```bash
npm run composer -- install
```

Then run everything after each change:

```bash
npm test
```

The tests run inside the Docker containers, so you don't need PHP or
Composer on your computer. They use their own database tables, so they
never touch your local site's content.

| Command | What it does |
| --- | --- |
| `npm test` | Runs the three checks below |
| `npm run lint` | Coding standards, security rules and PHP compatibility (PHPCS) |
| `npm run lint:fix` | Fixes formatting problems automatically |
| `npm run check:boundaries` | Checks for private WordPress APIs, direct database access, public entry points and outside URLs |
| `npm run test:php` | PHPUnit tests against a real WordPress. Any outside request or deprecation notice fails the test |
| `npm run test:php:multisite` | The same tests on a multisite network |
| `npm run build` | Builds the plugin zip in `dist/` |

### Automatic checks on GitHub

Every push to `main` and every pull request runs
[the Tests workflow](https://github.com/ajin/wp-category-featured-image-rotator/actions/workflows/tests.yml),
which also drives the Tests badge at the top of this page. It runs the
checks above on:

- WordPress 6.4 with PHP 8.0, the oldest versions supported
- the latest WordPress with PHP 8.3
- the next, unreleased WordPress with PHP 8.4

Each of these runs on a single site and on a multisite network. Then the
workflow builds the plugin zip, installs it on a fresh WordPress, and checks
that it loads without errors. The zip is kept with the run for 30 days.

### Releasing a new version

1. Update the version number in all the places it appears:

   ```bash
   bin/set-version.sh 0.2.0
   ```

2. Add a `## 0.2.0` section to [`CHANGELOG.md`](./CHANGELOG.md). It's used
   as the release notes.
3. Commit, push to `main`, then tag the release:

   ```bash
   git tag v0.2.0
   ```

   ```bash
   git push origin v0.2.0
   ```

Pushing the tag runs
[the Release workflow](https://github.com/ajin/wp-category-featured-image-rotator/actions/workflows/release.yml).
It runs all the tests and checks that the tag matches the plugin's version.
If everything passes, it publishes a
[release](https://github.com/ajin/wp-category-featured-image-rotator/releases)
with the plugin zip and its SHA-256 checksum. If anything fails, nothing is
published. Versions like `1.0.0-beta.1` are marked as pre-releases.

### Useful commands

Run these from the repo folder. See the
[`@wordpress/env` docs](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/#command-reference)
for more.

```bash
# Start or stop the local site
npx wp-env start
npx wp-env stop

# Apply changes to .wp-env.json, or after pulling new code
npx wp-env start --update

# Reset the database to a fresh install
npx wp-env clean all
npx wp-env start

# Show PHP and WordPress logs
npx wp-env logs

# Run a WP-CLI command on the local site
npx wp-env run cli wp plugin list
```

The same commands are available as npm scripts: `npm run env:start`,
`env:stop`, `env:update`, `env:clean`, `env:logs`, and
`npm run wp -- <command>`.
