# Blog Filtering & Category Organization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the default Kadence `/blog/` listing page with a fully custom page that renders all posts in a 3-column card grid with instant client-side category filtering.

**Architecture:** A new WordPress mu-plugin (`rosably-blog.php`) registers a `[rosably_blog]` shortcode that queries all published posts, embeds them as a JSON blob in the page HTML, then renders a hero + sticky filter bar + card grid shell. Inline JavaScript reads the JSON on load and handles all rendering and filtering — no AJAX, no page reloads. The Blog page (ID 2088) content is set to the shortcode via WP-CLI; no template files are changed.

**Tech Stack:** PHP (WordPress mu-plugin, WP_Query, shortcode API), vanilla JavaScript (ES5-compatible, inline), CSS (inline `<style>` block, Plus Jakarta Sans, matches existing site design system). Deployment: `docker cp` into running container `wordpress-wordpress-1`.

---

## File Map

| File | Action | Responsibility |
|---|---|---|
| `mu-plugins/rosably-blog.php` | **Create** | Shortcode registration, WP_Query, JSON data output, CSS, HTML shell, inline JS |
| WordPress DB — page ID 2088 | **Update via WP-CLI** | Set post_content to `<!-- wp:shortcode -->[rosably_blog]<!-- /wp:shortcode -->` |

---

## Task 1: Create `rosably-blog.php` — shortcode scaffold + data query

**Files:**
- Create: `mu-plugins/rosably-blog.php`

- [ ] **Step 1: Create the file with plugin header and shortcode registration**

Create `/opt/website/mu-plugins/rosably-blog.php`:

```php
<?php
/**
 * Plugin Name: Rosably Blog
 * Description: Custom blog listing page with client-side category filtering.
 */

add_shortcode( 'rosably_blog', 'rosably_render_blog' );

function rosably_render_blog() {
    // Build post data array
    $query = new WP_Query( [
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ] );

    $posts = [];
    if ( $query->have_posts() ) {
        while ( $query->have_posts() ) {
            $query->the_post();
            $id      = get_the_ID();
            $cats    = get_the_category( $id );
            $cat_arr = [];
            foreach ( $cats as $cat ) {
                $cat_arr[] = [ 'name' => $cat->name, 'slug' => $cat->slug ];
            }

            $raw_excerpt = get_the_excerpt();
            if ( ! $raw_excerpt ) {
                $raw_excerpt = wp_trim_words(
                    wp_strip_all_tags( get_the_content() ),
                    30,
                    '…'
                );
            }

            $posts[] = [
                'id'        => $id,
                'title'     => get_the_title(),
                'excerpt'   => $raw_excerpt,
                'permalink' => get_permalink(),
                'date'      => get_the_date( 'M j, Y' ),
                'categories'=> $cat_arr,
            ];
        }
        wp_reset_postdata();
    }

    $json = wp_json_encode( $posts );
    ob_start();
    ?>
    <script type="application/json" id="rsb-posts-data"><?php echo $json; ?></script>
    <?php
    return ob_get_clean();
}
```

- [ ] **Step 2: Copy the file into the running container and verify the shortcode registers**

```bash
docker cp /opt/website/mu-plugins/rosably-blog.php wordpress-wordpress-1:/var/www/html/wp-content/mu-plugins/rosably-blog.php
docker exec wordpress-wordpress-1 wp --allow-root --path=/var/www/html shortcode list 2>/dev/null | grep rosably_blog
```

Expected output: `rosably_blog` appears in the list.

- [ ] **Step 3: Smoke-test the data query by evaluating the shortcode**

```bash
docker exec wordpress-wordpress-1 wp --allow-root --path=/var/www/html eval 'echo do_shortcode("[rosably_blog]");' 2>/dev/null | python3 -c "import sys,json,re; m=re.search(r'<script[^>]*>(.*?)</script>',sys.stdin.read(),re.S); d=json.loads(m.group(1)); print(len(d),'posts'); [print(p[\"title\"][:50],\"|\",\",\".join(c[\"slug\"] for c in p[\"categories\"])) for p in d]"
```

Expected: 6 lines (one per published post) with title and category slugs, e.g.:
```
6 posts
Nonprofit Technology Funding Is Changing... | ai-strategy
Is Your Nonprofit Ready for AI?... | ai-strategy,nonprofit-operations
...
```

- [ ] **Step 4: Commit**

```bash
cd /opt/website && git add mu-plugins/rosably-blog.php && git commit -m "feat: rosably-blog shortcode — WP_Query data layer"
```

---

## Task 2: Add CSS + HTML shell to the shortcode

**Files:**
- Modify: `mu-plugins/rosably-blog.php`

- [ ] **Step 1: Replace the `ob_start()` block with the full CSS + shell markup**

Replace everything from `ob_start();` to the end of the function with:

```php
    ob_start();
    ?>
    <script type="application/json" id="rsb-posts-data"><?php echo $json; ?></script>

    <style>
    @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');
    /* Kill Kadence wrapper padding */
    .entry-content-wrap { padding: 0 !important; }
    .rsb-blog, .rsb-blog * { font-family: 'Plus Jakarta Sans', system-ui, sans-serif; box-sizing: border-box; }

    /* Hero */
    .rsb-blog-hero { background: #1A1A1A; padding: 56px 40px 48px; }
    .rsb-blog-hero h1 { font-size: 40px; font-weight: 800; color: #F6F1EB; margin: 0 0 10px; letter-spacing: -0.03em; line-height: 1.1; }
    .rsb-blog-hero p  { font-size: 16px; color: rgba(246,241,235,0.65); margin: 0; line-height: 1.7; }

    /* Filter bar */
    #rsb-filter-bar { position: sticky; top: 0; z-index: 10; background: #fff; border-bottom: 1px solid rgba(26,26,26,0.08); padding: 14px 40px; display: flex; gap: 8px; flex-wrap: wrap; }
    .rsb-pill { font-family: inherit; font-size: 10px; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; padding: 6px 14px; border-radius: 20px; border: 1px solid rgba(26,26,26,0.12); background: #F6F1EB; color: #555; cursor: pointer; transition: background 0.12s, color 0.12s, border-color 0.12s; }
    .rsb-pill.rsb-active, .rsb-pill:hover { background: #C05621; color: #fff; border-color: #C05621; }

    /* Grid */
    #rsb-card-grid { background: #F6F1EB; padding: 40px; display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; min-height: 200px; }
    @media (max-width: 1023px) { #rsb-card-grid { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 767px)  { #rsb-card-grid { grid-template-columns: 1fr; padding: 24px 20px; } #rsb-filter-bar { padding: 12px 20px; } }

    /* Cards */
    .rsb-card { background: #fff; border: 1px solid rgba(26,26,26,0.1); border-radius: 6px; padding: 22px; display: flex; flex-direction: column; text-decoration: none; transition: box-shadow 0.15s; }
    .rsb-card:hover { box-shadow: 0 4px 16px rgba(26,26,26,0.08); }
    .rsb-card-cat  { font-size: 9px; font-weight: 700; letter-spacing: 0.14em; text-transform: uppercase; color: #C05621; margin: 0 0 8px; }
    .rsb-card-bar  { width: 32px; height: 2px; background: #C05621; border-radius: 1px; margin: 0 0 12px; }
    .rsb-card-title { font-size: 16px; font-weight: 700; color: #1A1A1A; margin: 0 0 10px; line-height: 1.35; letter-spacing: -0.015em; }
    .rsb-card-exc  { font-size: 14px; color: #4A4A4A; line-height: 1.7; margin: 0 0 16px; flex-grow: 1; }
    .rsb-card-date { font-size: 12px; color: #999; margin: 0; }

    /* Empty state */
    .rsb-empty { grid-column: 1 / -1; text-align: center; padding: 60px 20px; font-size: 16px; color: #888; }
    </style>

    <div class="rsb-blog">
      <div class="rsb-blog-hero">
        <h1>Insights</h1>
        <p>AI strategy, operations, and more — for professional services firms and nonprofits.</p>
      </div>
      <div id="rsb-filter-bar"></div>
      <div id="rsb-card-grid"></div>
    </div>
    <?php
    return ob_get_clean();
```

- [ ] **Step 2: Copy updated file into the container**

```bash
docker cp /opt/website/mu-plugins/rosably-blog.php wordpress-wordpress-1:/var/www/html/wp-content/mu-plugins/rosably-blog.php
```

- [ ] **Step 3: Verify shortcode output now includes the HTML shell**

```bash
docker exec wordpress-wordpress-1 wp --allow-root --path=/var/www/html eval 'echo do_shortcode("[rosably_blog]");' 2>/dev/null | grep -c "rsb-blog-hero\|rsb-filter-bar\|rsb-card-grid"
```

Expected: `3` (all three elements present).

- [ ] **Step 4: Commit**

```bash
cd /opt/website && git add mu-plugins/rosably-blog.php && git commit -m "feat: blog shortcode — CSS + HTML shell"
```

---

## Task 3: Add inline JavaScript for rendering and filtering

**Files:**
- Modify: `mu-plugins/rosably-blog.php`

- [ ] **Step 1: Add the inline script block after `</div>` (closing `.rsb-blog`) and before `<?php`**

Insert this immediately after `</div>` (the closing `.rsb-blog` div) and before the closing `<?php`:

```html
    <script>
    (function() {
      var dataEl = document.getElementById('rsb-posts-data');
      if (!dataEl) return;
      var posts = JSON.parse(dataEl.textContent);
      var activeSlug = 'all';

      // Derive unique categories that have >=1 post
      var catMap = {};
      posts.forEach(function(p) {
        p.categories.forEach(function(c) {
          catMap[c.slug] = c.name;
        });
      });

      function renderPills() {
        var bar = document.getElementById('rsb-filter-bar');
        if (!bar) return;
        bar.innerHTML = '';

        var allBtn = document.createElement('button');
        allBtn.className = 'rsb-pill' + (activeSlug === 'all' ? ' rsb-active' : '');
        allBtn.textContent = 'All';
        allBtn.setAttribute('data-slug', 'all');
        allBtn.onclick = function() { setFilter('all'); };
        bar.appendChild(allBtn);

        Object.keys(catMap).forEach(function(slug) {
          var btn = document.createElement('button');
          btn.className = 'rsb-pill' + (activeSlug === slug ? ' rsb-active' : '');
          btn.textContent = catMap[slug];
          btn.setAttribute('data-slug', slug);
          btn.onclick = function() { setFilter(slug); };
          bar.appendChild(btn);
        });
      }

      function renderCards() {
        var grid = document.getElementById('rsb-card-grid');
        if (!grid) return;
        grid.innerHTML = '';

        var filtered = activeSlug === 'all'
          ? posts
          : posts.filter(function(p) {
              return p.categories.some(function(c) { return c.slug === activeSlug; });
            });

        if (!filtered.length) {
          var empty = document.createElement('div');
          empty.className = 'rsb-empty';
          empty.textContent = 'Nothing here yet — check back soon.';
          grid.appendChild(empty);
          return;
        }

        filtered.forEach(function(p) {
          var firstCat = p.categories.length ? p.categories[0].name : '';
          var a = document.createElement('a');
          a.className = 'rsb-card';
          a.href = p.permalink;
          a.innerHTML =
            '<div class="rsb-card-cat">' + escHtml(firstCat) + '</div>' +
            '<div class="rsb-card-bar"></div>' +
            '<div class="rsb-card-title">' + escHtml(p.title) + '</div>' +
            '<div class="rsb-card-exc">'  + escHtml(p.excerpt) + '</div>' +
            '<div class="rsb-card-date">' + escHtml(p.date)    + '</div>';
          grid.appendChild(a);
        });
      }

      function setFilter(slug) {
        activeSlug = slug;
        renderPills();
        renderCards();
      }

      function escHtml(str) {
        return String(str)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;');
      }

      document.addEventListener('DOMContentLoaded', function() {
        renderPills();
        renderCards();
      });
    })();
    </script>
```

- [ ] **Step 2: Copy updated file into the container**

```bash
docker cp /opt/website/mu-plugins/rosably-blog.php wordpress-wordpress-1:/var/www/html/wp-content/mu-plugins/rosably-blog.php
```

- [ ] **Step 3: Verify the script block is present in shortcode output**

```bash
docker exec wordpress-wordpress-1 wp --allow-root --path=/var/www/html eval 'echo do_shortcode("[rosably_blog]");' 2>/dev/null | grep -c "DOMContentLoaded\|renderCards\|renderPills"
```

Expected: `3`.

- [ ] **Step 4: Commit**

```bash
cd /opt/website && git add mu-plugins/rosably-blog.php && git commit -m "feat: blog shortcode — client-side filtering JS"
```

---

## Task 4: Wire the shortcode into the Blog page and verify live

**Files:**
- WordPress DB — Blog page ID 2088 (updated via WP-CLI inside container)

- [ ] **Step 1: Set the Blog page content to the shortcode block**

```bash
docker exec wordpress-wordpress-1 wp --allow-root --path=/var/www/html post update 2088 --post_content='<!-- wp:shortcode -->[rosably_blog]<!-- /wp:shortcode -->'
```

Expected output: `Success: Updated post 2088.`

- [ ] **Step 2: Flush the page cache**

```bash
docker exec wordpress-wordpress-1 find /var/www/html/wp-content/cache -type f \( -name "*.html" -o -name "*.php" \) -delete 2>/dev/null; echo "cache cleared"
```

Expected: `cache cleared`

- [ ] **Step 3: Verify the live page renders the hero and filter bar**

```bash
curl -s "https://rosably.com/blog/" | grep -c "rsb-blog-hero\|rsb-filter-bar\|rsb-posts-data"
```

Expected: `3`

- [ ] **Step 4: Verify the JSON data blob contains all 6 posts**

```bash
curl -s "https://rosably.com/blog/" | python3 -c "
import sys, json, re
html = sys.stdin.read()
m = re.search(r'<script type=\"application/json\" id=\"rsb-posts-data\">(.*?)</script>', html, re.S)
posts = json.loads(m.group(1))
print(len(posts), 'posts found')
for p in posts:
    cats = ','.join(c['slug'] for c in p['categories'])
    print(' -', p['title'][:55], '|', cats)
"
```

Expected: `6 posts found` followed by one line per post.

- [ ] **Step 5: Check no "Stack Audit" or old Kadence blog markup is on the page**

```bash
curl -s "https://rosably.com/blog/" | grep -i "stack.audit\|article.*loop-entry\|entry-list-item" | wc -l
```

Expected: `0`

- [ ] **Step 6: Commit**

```bash
cd /opt/website && git add mu-plugins/rosably-blog.php && git commit -m "feat: wire [rosably_blog] shortcode into Blog page (ID 2088)"
```

(Note: the page content change is in the WordPress database, not a file — the commit documents the deploy state.)

- [ ] **Step 7: Push to remote**

```bash
cd /opt/website && git push origin main
```

---

## Task 5: Manual smoke-test checklist

No automated test runner exists for this project. Run these checks manually in a browser at `https://rosably.com/blog/`.

- [ ] **Hero strip** renders with dark background, "Insights" heading, subtitle text
- [ ] **Filter pills** appear: "All" is orange/active, remaining pills match categories that have posts (AI Strategy, Cybersecurity, Nonprofit Operations, Hiring & HR — NOT Professional Services)
- [ ] **All posts** render as cards with: orange category label, orange accent bar, title (linked), excerpt, date
- [ ] **Clicking a category pill** instantly filters cards to that category only, pill turns orange, "All" goes inactive
- [ ] **Clicking "All"** restores all posts
- [ ] **Multi-category post** ("Is Your Nonprofit Ready for AI?" — tagged AI Strategy + Nonprofit Operations) appears under both category filters
- [ ] **Sticky filter bar** stays fixed at top of viewport when scrolling through cards
- [ ] **Responsive — tablet** (resize to ~900px): grid becomes 2 columns
- [ ] **Responsive — mobile** (resize to ~375px): grid becomes 1 column, filter pills wrap
- [ ] **Page URL** is still `/blog/` — unchanged
