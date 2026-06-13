# Blog Filtering & Category Organization — Design Spec
**Date:** 2026-06-13  
**Status:** Approved

---

## Overview

Replace the default Kadence blog listing page at `/blog/` with a fully custom `wp:html` block implementation. Visitors can filter posts by content area using client-side JavaScript — instant, no page reload. The existing WordPress categories, URL, and page ID are preserved unchanged.

---

## Categories

Five categories, established and in use:

| Category | Slug | Current posts |
|---|---|---|
| AI Strategy | ai-strategy | 3 |
| Cybersecurity | cybersecurity | 2 |
| Nonprofit Operations | nonprofit-operations | 4 |
| Hiring & HR | hiring-hr | 1 |
| Professional Services | professional-services | 0 |

**Professional Services** is hidden from the filter bar until it has at least one published post. Filter pills are generated dynamically from categories that have ≥1 post.

---

## Page Structure

Top-to-bottom layout on `/blog/` (page ID 2088):

1. **Hero strip** — dark background (`#1A1A1A`), page title "Insights", short subtitle. Matches the Services page hero pattern.
2. **Filter bar** — `position: sticky; top: 0; z-index: 10; background: #fff`. Horizontal row of pills: "All" (default, active) + one pill per category with ≥1 post. Active pill is orange-filled (`#C05621`). Inactive pills are cream with a light border.
3. **Card grid** — 3 columns desktop / 2 columns tablet (768–1023px) / 1 column mobile (<768px). Cards rendered by JavaScript from the embedded JSON data.
4. **Empty state** — if no posts match the active filter, a centered message: *"Nothing here yet — check back soon."*

---

## Card Design

Each card (white, rounded, subtle border, hover shadow):

- **Category label** — 9px uppercase orange (`#C05621`), letter-spaced
- **Orange accent bar** — 32px × 2px, sits between category label and title
- **Title** — 16px, 700 weight, dark ink, links to post permalink
- **Excerpt** — 14px, 1.7 line-height, medium gray
- **Date** — 12px, light gray (e.g. "May 25, 2026")

Cards are full links (`<a>` wrapping the card). Hover: `box-shadow: 0 4px 16px rgba(26,26,26,0.08)`.

---

## Data Flow

### PHP shortcode (`[rosably_blog]`)

Registered in a new file: `mu-plugins/rosably-blog.php`.

1. Runs `WP_Query` for all published posts (`posts_per_page: -1`), ordered by date descending.
2. For each post: collects `ID`, `post_title`, trimmed excerpt (via `get_the_excerpt()`), `permalink`, formatted date, and array of category `{name, slug}` objects.
3. JSON-encodes the array and outputs it in a `<script type="application/json" id="rsb-posts-data">` tag.
4. Outputs a `<style>` block containing the page CSS (including `.entry-content-wrap { padding: 0 !important; }` to remove Kadence wrapper padding, matching the Services page pattern) plus the shell markup: hero section, filter bar container (`id="rsb-filter-bar"`), card grid container (`id="rsb-card-grid"`), and the inline JavaScript.

### Inline JavaScript

On `DOMContentLoaded`:
1. Reads the JSON from `#rsb-posts-data`.
2. Derives unique categories (from posts with ≥1 post each) and renders filter pills into `#rsb-filter-bar`. Prepends an "All" pill (active by default).
3. Renders all post cards into `#rsb-card-grid`.
4. On pill click: sets clicked pill active (orange), re-renders grid filtered to posts where `post.categories` contains the selected slug. "All" shows everything.

A post tagged to multiple categories appears under any of its matching category pills.

---

## WordPress Integration

- The Blog page (ID 2088, slug `/blog/`) has its `post_content` replaced with a single `<!-- wp:shortcode -->[rosably_blog]<!-- /wp:shortcode -->` block via WP-CLI.
- No WordPress template files are modified.
- No new plugins required.
- The `.gitignore` already ignores `.env`; add `.superpowers/` to `.gitignore` as well.

---

## Responsive Behavior

| Breakpoint | Grid columns | Filter pills |
|---|---|---|
| ≥1024px | 3 | Single row (wrap if needed) |
| 768–1023px | 2 | Wrap to multiple rows |
| <768px | 1 | Wrap to multiple rows, smaller padding |

Filter bar remains sticky at all breakpoints.

---

## Files Changed

| File | Change |
|---|---|
| `mu-plugins/rosably-blog.php` | New file — shortcode registration, WP_Query, JSON output, markup + JS |
| Blog page (ID 2088) | `post_content` updated via WP-CLI to `[rosably_blog]` shortcode |
| `.gitignore` | Add `.superpowers/` |

The updated plugin file must be `docker cp`'d into the running container (same pattern as `rosably-assessment.php`) — no image rebuild needed since the volume persists the live plugin directory.

---

## Out of Scope

- Paginating posts (all posts load at once; revisit if post count exceeds ~50)
- Bookmarkable/shareable filtered URLs (AJAX approach, future work)
- Search within the blog
- Featured images on cards
