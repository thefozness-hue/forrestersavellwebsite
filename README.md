# forrestersavell.com — static site

This repository contains **forrestersavell.com rebuilt as a fully static site**,
migrated from WordPress in July 2026. There is no PHP, no database, no admin
login and no plugins — nothing to update and effectively nothing to hack. The
layout, content and behaviour are identical to the WordPress version.

## Layout

```
site/               ← the deployable web root (upload its CONTENTS to public_html)
  index.html        ← the one-page site (hero / artists grid / Spotify / Nail The Mix / contact)
  service/…         ← the "Service: Mixer/Producer/…" archive pages (still indexed by Google)
  tag/featured/     ← tag archive page
  thanks/           ← contact-form thank-you page
  404.html          ← not-found page
  .htaccess         ← redirects, 404 handling, security headers, caching
  robots.txt, sitemap.xml
  wp-content/…      ← images, CSS and 4 small JS files kept from the old site
                       (just static files — the folder name is kept so that no
                       image URL on the internet breaks)
scripts/migrate.py  ← the one-time script that produced site/ from a wget
                      mirror of the WordPress site (kept for reference)
```

## What changed vs. WordPress

* **Contact form**: Ninja Forms is replaced by a plain HTML form posting to
  [FormSubmit](https://formsubmit.co) (free, no account). Messages go to
  `info@forrestersavell.com`.
  **One-time activation**: the first time someone submits the form, FormSubmit
  emails an activation link to that address — click it once and the form is
  live. Optionally, afterwards you can replace the email in the form's
  `action=` (in `site/index.html`) with the random alias FormSubmit gives you,
  so the address isn't visible in the page source.
* Successful submissions land on `/thanks/`.
* Removed: jQuery, Backbone, Underscore, Ninja Forms JS/CSS, WordPress emoji
  scripts, REST API/oEmbed/RSD/feed links (≈500 KB less JavaScript; the only
  scripts left are Google Analytics and four small theme scripts for the menu,
  smooth scrolling and back-to-top).
* Fixed: the nav menu's About / Artists / Connect links pointed at deleted
  WordPress pages (`?page_id=…`) and returned 404 on the live site; they now go
  to `/`, `/#artists` and `/#contact`.
* The 58 `/project/<album>/` pages were byte-identical copies of the homepage
  (WordPress template leftover); `.htaccess` now 301-redirects them to
  `/#artists`, and old WordPress endpoints (`/wp-admin`, `/wp-json`,
  `xmlrpc.php`) return 410 so bots stop probing them.
* Unchanged: all markup, CSS, images, the Spotify embed, Google Analytics,
  Yoast meta/schema tags, and the `/service/…` archive pages Google has
  indexed.

## Deploying to hostm

1. Take a final backup of the current site (hostm control panel → backup).
2. Upload the **contents** of `site/` into `public_html/` (File Manager or
   SFTP), replacing what's there. Make sure `.htaccess` is included (hidden
   file).
3. Delete the WordPress files and database once you're happy — that's the
   security payoff: `wp-admin/`, `wp-includes/`, all PHP files, and drop the
   WordPress MySQL database. (The `wp-content` folder that comes from `site/`
   stays — it's only images/CSS/JS.)
4. Send a test message through the contact form and click the FormSubmit
   activation link that arrives at info@forrestersavell.com.

The site also works on any static host (Cloudflare Pages, Netlify, GitHub
Pages) — `.htaccess` is Apache-only, so on those hosts the redirects/404 would
need their equivalent config.

## Updating content later

Everything is plain HTML — edit `site/index.html` and re-upload. To add a new
release to the Artists grid, copy one of the existing
`<div class="gb-grid-column …">` blocks inside the `id="artists"` section,
change the artist/title/roles text and point it at a new 600×600 image in
`wp-content/uploads/`. (Or open this repo in Claude Code and ask it to add the
release for you.)

## Local preview

```
cd site && python3 -m http.server 8000
# → http://localhost:8000
```
