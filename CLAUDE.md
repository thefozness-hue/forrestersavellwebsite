# Working on forrestersavell.com (static site)

This is the static, no-WordPress rebuild of forrestersavell.com. See
`README.md` for the full migration story and deploy steps. The deployable web
root is `site/`. There is no backend, database, or build step — the HTML is
served as-is.

The owner (Forrester) updates the site by **asking Claude** rather than using
an admin panel. The most common request is **"add a new release/artist to the
site."** This file documents how to do that correctly and consistently.

## Adding a new release to the Artists grid

A "release" is one album/single tile in the grid: an album image, the artist
name, the release title, and the role(s) Forrester played (Master / Mixer /
Producer / Engineer / Vocal Mixer).

### 1. The image

Forrester provides one **square album cover** (ideally 1200×1200 or larger).
Generate the responsive sizes the markup expects and save them under
`site/wp-content/uploads/` using a slug like `<artist>-<title>`:

```
<slug>-300x300.jpg
<slug>-600x600.jpg      ← the default `src`
<slug>-768x768.jpg
<slug>-1200x1200.jpg
```

(The original WordPress images also had 1536 and 2048 variants; matching
600/768/1200 in `srcset` is plenty. Only reference sizes you actually create.)
Use Pillow or ImageMagick, e.g.:

```
for s in 300 600 768 1200; do
  magick source.jpg -resize ${s}x${s}^ -gravity center -extent ${s}x${s} \
    site/wp-content/uploads/<slug>-${s}x${s}.jpg
done
```

### 2. The grid tile (homepage — the primary showcase)

In `site/index.html`, inside `<div ... id="artists">`, each tile is a
`gb-grid-column` block. **Copy an existing tile** and change only the image
URLs, artist (`<h2>`), title (`<h3>`), and roles. Newest work goes **first**
in the grid. Keep all the `gb-*` utility classes exactly as they are — they
carry the styling. Template:

```html
<div class="gb-grid-column gb-grid-column-6e31cfb5 gb-query-loop-item project type-project status-publish has-post-thumbnail hentry SERVICE_CLASSES"><div class="gb-container gb-container-6e31cfb5">
<figure class="gb-block-image gb-block-image-8114866f"><img decoding="async" width="600" height="600" src="/wp-content/uploads/SLUG-600x600.jpg" class="gb-image-8114866f" alt="ARTIST TITLE" srcset="/wp-content/uploads/SLUG-600x600.jpg 600w, /wp-content/uploads/SLUG-1200x1200.jpg 1200w, /wp-content/uploads/SLUG-300x300.jpg 300w, /wp-content/uploads/SLUG-768x768.jpg 768w" sizes="(max-width: 600px) 100vw, 600px" /></figure>
<div class="gb-container gb-container-7db1c903 overlay">
<h2 class="gb-headline gb-headline-6ea3cdec gb-headline-text">ARTIST</h2>
<h3 class="gb-headline gb-headline-ff978106 gb-headline-text">TITLE</h3>
<p class="gb-headline gb-headline-0bcc2783 services gb-headline-text">ROLE_SPANS</p>
</div>
</div></div>
```

Roles — use one `<span>` per role, comma-separated, in this canonical order
(Master, Mixer, Producer, Engineer, Vocal Mixer). Each role needs BOTH the
matching `term-*` class here AND the matching `service-*` class on the outer
`gb-grid-column` div (`SERVICE_CLASSES` above):

| Role        | span                                                        | outer class          |
|-------------|-------------------------------------------------------------|----------------------|
| Master      | `<span class="post-term-item term-master">Master</span>`     | `service-master`     |
| Mixer       | `<span class="post-term-item term-mixer">Mixer</span>`       | `service-mixer`      |
| Producer    | `<span class="post-term-item term-producer">Producer</span>` | `service-producer`   |
| Engineer    | `<span class="post-term-item term-engineer">Engineer</span>` | `service-engineer`   |
| Vocal Mixer | `<span class="post-term-item term-vocal-mixer">Vocal Mixer</span>` | `service-vocal-mixer` |

Also add `tag-featured` to the outer classes if it should appear on the
`/tag/featured/` page.

### 3. The service archive pages (secondary — keep in sync)

The same release ALSO belongs on the relevant `site/service/<role>/index.html`
archive page(s) — e.g. a Mixer+Master release appears on `service/mixer/` and
`service/master/`. These pages use a similar (slightly different) `article`
markup and are **paginated** (older items on `/page/2/` etc.). Insert the new
item at the top of the first page of each matching service. This is fiddlier
than the homepage; if the owner only cares about the homepage showcase, it's
fine to update `index.html` alone and note that the archive pages weren't
touched — but for SEO completeness, keeping them in sync is better.

### 4. After editing — verify and deliver

1. Rebuild the upload zip: `cd site && zip -r ../preview-site.zip .`
2. Render `site/index.html` in a headless browser (Chromium at
   `/opt/pw-browsers/chromium-1194/chrome-linux/chrome`) and screenshot the
   Artists grid to confirm the new tile looks right and the image loads.
3. Hand the owner the updated `preview-site.zip` (via SendUserFile) and tell
   them to upload/extract it into `public_html/` on hostm, OR — if the site is
   later connected to git auto-deploy — just commit and push.

## Deploying / hosting

The site is hosted on hostm (cPanel, Apache — so `.htaccess` applies). Updates
currently ship by uploading the contents of `site/` into `public_html/` via
File Manager. `preview-site.zip` is a git-ignored build artifact, rebuilt from
`site/` any time with `cd site && zip -r ../preview-site.zip .`.

## Ground rules

- Keep changes matching the surrounding markup and the existing `gb-*` class
  names — don't "modernize" the HTML or strip classes; the inline
  `generateblocks` CSS depends on them.
- Never reintroduce a server-side backend (PHP, a database, an admin login).
  The whole point of this rebuild is that there's nothing dynamic to exploit.
- The contact form posts to a FormSubmit alias (see `scripts/migrate.py`),
  which keeps the real email out of the page source. Don't replace it with the
  plain address.
