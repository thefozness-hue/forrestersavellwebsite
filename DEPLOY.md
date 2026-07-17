# Deploying forrestersavell.com

The site is plain static files in [`site/`](site/). Deploying just means copying
`site/`'s contents into `public_html/` on hostm. Two ways to do it:

## Option 1 — one command from your Mac (recommended)

[`deploy.sh`](deploy.sh) syncs `site/` up to the server over FTPS/SFTP,
uploading only the files that changed. It never deletes anything on the
server, so the preview subdomain folder and other server-only files are safe.

**One-time setup**

1. Install lftp:
   ```
   brew install lftp
   ```
2. Create your config from the template and fill it in:
   ```
   cp deploy.env.example deploy.env
   ```
   Open `deploy.env` and set your hostm login. The simplest, safest login is a
   dedicated FTP account: in cPanel → **FTP Accounts**, create one with its
   **Directory** set to `public_html`, then put that username/password in
   `deploy.env` and set `DEPLOY_REMOTE_DIR=.`. `deploy.env` is gitignored, so
   the password never gets committed.

**Every deploy after that**

```
./deploy.sh --dry-run   # preview what will change (uploads nothing)
./deploy.sh             # publish
```

That's the whole loop — no zips, no File Manager.

## Option 2 — manual upload (fallback)

Rebuild the zip and upload it in cPanel File Manager:
```
cd site && zip -r ../preview-site.zip .
```
Then File Manager → `public_html` → Upload → Extract → overwrite.

## Notes

- The contact form (`site/contact.php`) and `.htaccess` are part of `site/`,
  so they deploy automatically like everything else.
- To add a new release, see the recipe in [`CLAUDE.md`](CLAUDE.md) — or just
  ask Claude to do it, then run `./deploy.sh`.
