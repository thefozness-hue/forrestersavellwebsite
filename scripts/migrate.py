#!/usr/bin/env python3
"""One-time migration: transform a wget mirror of the WordPress site
forrestersavell.com into a self-contained static site under site/.

Usage: python3 scripts/migrate.py <mirror-root> <output-dir>
  <mirror-root> is the directory containing index.html (the wget mirror of
  https://forrestersavell.com/, fetched WITHOUT --convert-links).

What it does:
  * copies pages and assets, renaming wget's "file@ver=x" artifacts
  * strips WordPress runtime cruft (emoji scripts, REST/feed/oEmbed/RSD
    links, generator meta, shortlink)
  * removes Ninja Forms and its dependency chain (jQuery, jQuery-migrate,
    Underscore, Backbone, NF templates/styles) and swaps in a static
    HTML form posting to FormSubmit
  * fixes the nav menu links that pointed at deleted ?page_id pages
  * rewrites same-domain asset/page URLs to root-relative (canonical,
    og:*, twitter:* and JSON-LD stay absolute)
  * drops the 58 /project/ pages (byte-identical clones of the homepage);
    .htaccess 301s them to /#artists instead
"""
import os
import re
import shutil
import sys

# The contact form posts to site/contact.php — a small hardened mail handler
# that emails submissions from the site's own domain (clean, no third-party
# branding). See site/contact.php for the handler itself.
STATIC_FORM = """<div id="nf-form-1-cont" class="nf-form-cont" aria-live="polite" role="form">
<form class="static-contact-form" action="/contact.php" method="POST">
<input type="text" name="_honey" style="display:none !important" tabindex="-1" autocomplete="off" aria-hidden="true">
<p class="scf-required">All fields are required</p>
<div class="scf-field"><label class="screen-reader-text" for="scf-name">Name</label><input id="scf-name" type="text" name="name" placeholder="Name" required></div>
<div class="scf-field"><label class="screen-reader-text" for="scf-email">Email Address</label><input id="scf-email" type="email" name="email" placeholder="Email Address" required></div>
<div class="scf-field"><label class="screen-reader-text" for="scf-message">Message</label><textarea id="scf-message" name="message" placeholder="Message" rows="6" required></textarea></div>
<div class="scf-field scf-submit"><input type="submit" value="Send"></div>
</form>
</div>
<style id="static-contact-form-css">
.static-contact-form .scf-field{margin-bottom:20px}
.static-contact-form input[type=text],.static-contact-form input[type=email],.static-contact-form textarea{width:100%;padding:12px 16px;border:1px solid var(--base);border-radius:0;box-sizing:border-box}
.static-contact-form textarea{resize:vertical}
.static-contact-form input[type=submit]{padding:14px 18px;border:0;cursor:pointer}
.static-contact-form .scf-required{margin-bottom:20px}
</style>"""


def read(path):
    with open(path, encoding="utf-8", errors="surrogateescape") as f:
        return f.read()


def write(path, text):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8", errors="surrogateescape") as f:
        f.write(text)


def remove_between(src, start_marker, end_marker, include_end=True, occurrences=-1):
    """Remove text spans from start_marker up to (and incl.) end_marker."""
    out = src
    count = 0
    while occurrences < 0 or count < occurrences:
        i = out.find(start_marker)
        if i < 0:
            break
        j = out.find(end_marker, i + len(start_marker))
        if j < 0:
            break
        j += len(end_marker) if include_end else 0
        out = out[:i] + out[j:]
        count += 1
    return out


def clean_html(src):
    # --- WordPress head cruft ------------------------------------------------
    src = re.sub(r'<link rel="alternate"[^>]*type="application/rss\+xml"[^>]*/>\n?', "", src)
    src = re.sub(r'<link rel="alternate"[^>]*oembed[^>]*/>\n?', "", src)
    src = re.sub(r'<link rel="https://api\.w\.org/"[^>]*/>', "", src)
    src = re.sub(r'<link rel="alternate" title="JSON"[^>]*/>', "", src)
    src = re.sub(r'<link rel="EditURI"[^>]*/>', "", src)
    src = re.sub(r"<link rel='shortlink'[^>]*/>\n?", "", src)
    src = re.sub(r'<meta name="generator"[^>]*/>\n?', "", src)
    # emoji machinery
    src = remove_between(src, '<style id="wp-emoji-styles-inline-css">', "</style>")
    src = remove_between(src, '<script id="wp-emoji-settings"', "</script>")
    src = re.sub(
        r"<script(?![^>]*\bsrc=)[^>]*>(?:(?!</script>).)*wp-emoji(?:(?!</script>).)*</script>",
        "",
        src,
        flags=re.S,
    )

    # --- Ninja Forms + its JS stack ------------------------------------------
    src = remove_between(src, '<noscript class="ninja-forms-noscript-message">', "</noscript>")
    # the giant inline form-definition script (preceded by a workaround comment)
    src = remove_between(
        src,
        "<!-- That data is being printed as a workaround",
        "</script>",
    )
    # nf template scripts
    src = re.sub(r"<script id=.tmpl-nf-[^>]*>.*?</script>\s*", "", src, flags=re.S)
    # nf/jquery/underscore/backbone script tags (external + inline extras)
    for sid in (
        "jquery-core-js",
        "jquery-migrate-js",
        "underscore-js",
        "backbone-js",
        "nf-front-end-deps-js",
        "nf-front-end-js",
        "nf-front-end-js-extra",
    ):
        src = re.sub(
            r"<script[^>]*id=.%s.[^>]*>.*?</script>\s*" % re.escape(sid), "", src, flags=re.S
        )
    # nf stylesheets
    src = re.sub(r"<link[^>]*ninja-forms[^>]*/>\n?", "", src)
    # swap the form container for the static form
    src = re.sub(
        r'<div id="nf-form-1-cont".*?<div class="nf-loading-spinner"></div>\s*</div>',
        lambda m: STATIC_FORM,
        src,
        flags=re.S,
    )

    # --- broken nav links (pointed at deleted WP pages) -----------------------
    src = src.replace('href="https://forrestersavell.com/?page_id=7"', 'href="/"')
    src = src.replace('href="https://forrestersavell.com/?page_id=9"', 'href="/#artists"')
    src = src.replace('href="https://forrestersavell.com/?page_id=13"', 'href="/#contact"')

    # --- strip ?ver= cache-busters from local asset URLs ----------------------
    src = re.sub(r"(forrestersavell\.com/[^\s\"'>]+)\?(?:ver|v)=[0-9a-zA-Z.\-]+", r"\1", src)

    # --- root-relative URLs (href/src/srcset/action only; canonical/og/JSON-LD
    #     keep the absolute domain) --------------------------------------------
    def relativize(m):
        attr, quote, rest = m.group(1), m.group(2), m.group(3)
        return "%s=%s/%s" % (attr, quote, rest)

    src = re.sub(
        r"(href|src|srcset|action)=([\"'])https?://forrestersavell\.com/([^\"']*)",
        relativize,
        src,
    )
    # canonical must stay absolute — restore it
    src = src.replace(
        '<link rel="canonical" href="/',
        '<link rel="canonical" href="https://forrestersavell.com/',
    )
    # srcset contains additional space-separated URLs
    src = re.sub(r"(,\s*)https?://forrestersavell\.com/", r"\g<1>/", src)
    return src


def main():
    mirror, out = sys.argv[1], sys.argv[2]
    if os.path.exists(out):
        shutil.rmtree(out)

    keep_html = ["index.html"]
    for root, _dirs, files in os.walk(mirror):
        rel = os.path.relpath(root, mirror)
        for name in files:
            relpath = os.path.normpath(os.path.join(rel, name))
            # skip the 58 homepage-clone project pages and WP endpoints
            if relpath.split(os.sep)[0] in ("project", "wp-json"):
                continue
            # skip runtime we no longer ship
            if "ninja-forms" in relpath or relpath.startswith("wp-includes"):
                continue
            srcp = os.path.join(root, name)
            # normalize wget's "name@ver=…" artifacts back to clean names
            clean = re.sub(r"@(?:ver|v)=[^/]*$", "", relpath)
            dstp = os.path.join(out, clean)
            if name.endswith(".html"):
                write(dstp, clean_html(read(srcp)))
            elif clean.endswith(".css"):
                css = read(srcp)
                css = re.sub(r"https?://forrestersavell\.com/", "/", css)
                write(dstp, css)
            else:
                os.makedirs(os.path.dirname(dstp), exist_ok=True)
                shutil.copy2(srcp, dstp)
    print("done ->", out)


if __name__ == "__main__":
    main()
