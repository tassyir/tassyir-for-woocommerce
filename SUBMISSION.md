# Publishing Tassyir for WooCommerce on wordpress.org

This is for the owner. It covers the first submission, the review, the SVN upload after approval, and
how every release works from then on. Nothing here is done automatically.

There are two builds of the same plugin, with the same slug (`tassyir-for-woocommerce`) and the same
version number:

| Build | Command | File | Updates come from |
|---|---|---|---|
| Self-hosted (download from the Tassyir dashboard) | `bin/build.sh` | `dist/tassyir-for-woocommerce-<version>.zip` | Tassyir (`/woo-plugin/update.json`) |
| wordpress.org | `bin/build.sh --wporg` | `dist/tassyir-for-woocommerce-<version>-wporg.zip` | wordpress.org |

The wordpress.org build contains no self-update code: no `Updater.php`, no `self-update.php`, no
plugin-update-checker, no `vendor-prefixed/`, and no "Updates" bullet in `readme.txt`. Because
the slug is the same, a site on the self-hosted zip also gets wordpress.org updates once the
listing exists.

## 1. Create the company account (once)

1. Go to https://login.wordpress.org/register and register the username **`tassyir`**, with a
   company mailbox that several people can read (e.g. `plugins@tassyir.io`). Every review email goes
   there. `readme.txt` already says `Contributors: tassyir`, so the username must be exactly that.
2. Turn on two-factor authentication: https://profiles.wordpress.org/tassyir/profile/edit/group/1/?screen=security
   (Account & Security → Two-Factor Authentication). wordpress.org requires 2FA to commit to SVN.
   Save the backup codes in the company password manager.
3. On the same Security page, create an **SVN password**. SVN does not take the account password,
   only this one.

## 2. Submit the zip

1. Build it: `bin/build.sh --wporg` → `dist/tassyir-for-woocommerce-1.0.0-wporg.zip`.
2. Signed in as `tassyir`, open https://wordpress.org/plugins/developers/add/, accept the guidelines
   and upload that zip. Do **not** upload the self-hosted zip: it contains a self-updater, which is
   refused (guideline 8).
3. The page shows the slug it will reserve. It must be `tassyir-for-woocommerce`, which comes from
   the plugin name. If it shows anything else, reply to the confirmation email and ask for that
   slug **before** approval. It cannot be changed after approval.

Already checked on this zip: Plugin Check (the official `plugin-check` plugin, run on the
installed wordpress.org build) reports 0 errors. Its one warning is that `load_plugin_textdomain()`
is discouraged. The call is kept on purpose: it loads the bundled Arabic and French translations
until translate.wordpress.org has language packs for the plugin. See step 6.

## 3. The review

- The first automated email arrives within minutes. A human review follows, typically in **1–3
  weeks** (the add page shows how many plugins are waiting).
- The reviewer writes from `plugins@wordpress.org`. **Reply to that same email thread**, and
  within **7 days**. A submission without an answer is eventually closed and has to be submitted
  again. Answer even if it is only "we are working on it".
- If they ask for changes, fix them here, rebuild with `bin/build.sh --wporg`, and send the new
  zip the way the email says (usually uploaded from the same add page). Keep the version number
  unchanged until approval.
- Points they often ask about, already handled here:
  - External services are disclosed in the readme, with the terms and privacy links.
  - No echoed `<script>` or `<style>`: the scripts and styles are enqueued files under `assets/`.
  - Output is escaped.
  - `base64` is used only for the HMAC signature and secret that the API defines.
  - The activation redirect happens once, only when not connected, and never for bulk, network,
    WP-CLI or AJAX activation.
  - The connect notice can be dismissed per user.
  - The privacy text is added with `wp_add_privacy_policy_content()`.

## 4. After approval: the SVN upload

The approval email gives the repository `https://plugins.svn.wordpress.org/tassyir-for-woocommerce`.
The listing stays empty until something is committed to it. With Subversion installed (Windows:
TortoiseSVN with its command-line tools, or `svn` in WSL):

```bash
# 1. Check out the (empty) repository next to this repo.
svn checkout https://plugins.svn.wordpress.org/tassyir-for-woocommerce ../tassyir-svn
cd ../tassyir-svn

# 2. trunk = the contents of the wordpress.org zip (not the zip itself, not this git repo).
rm -rf /tmp/tassyir-wporg && mkdir /tmp/tassyir-wporg
unzip -q ../woocommerce-plugin/dist/tassyir-for-woocommerce-1.0.0-wporg.zip -d /tmp/tassyir-wporg
rsync -a --delete /tmp/tassyir-wporg/tassyir-for-woocommerce/ trunk/

# 3. assets/ = listing images (never inside trunk).
cp ../woocommerce-plugin/.wordpress-org/*.png assets/
svn propset svn:mime-type image/png assets/*.png

# 4. Tell SVN about added and removed files.
svn add --force trunk assets
svn status | awk '/^!/ {print $2}' | xargs -r svn delete

# 5. Tag the release (Stable tag in readme.txt points here).
svn cp trunk tags/1.0.0

# 6. Commit (username tassyir, the SVN password from step 1).
svn ci -m "Tassyir for WooCommerce 1.0.0" --username tassyir
```

The listing appears within about 15 minutes. `.wordpress-org/` in this repo holds what goes into
`assets/`:

| File | Size | Status |
|---|---|---|
| `screenshot-1.png` … `screenshot-5.png` | 1280 wide | done (fake shop data, captions in `readme.txt`) |
| `icon-128x128.png`, `icon-256x256.png` | 128×128, 256×256 | **to design** (an `icon.svg` may be added too) |
| `banner-772x250.png`, `banner-1544x500.png` | 772×250, 1544×500 | **to design** |
| `banner-772x250-rtl.png`, `banner-1544x500-rtl.png` | same | optional, shown to RTL (Arabic) visitors |

Changing only images later needs only `assets/`, with no new version or tag.

## 5. Switch the backend once the listing is live

Set **`WOO_PLUGIN_WPORG_LISTED=true`** on the backend. Tassyir → Apps → WooCommerce then sends
merchants to `wp-admin/plugin-install.php?tab=plugin-information&plugin=tassyir-for-woocommerce` to
install from wordpress.org, instead of the zip upload page.

Optional: point `WOO_PLUGIN_DOWNLOAD_URL` at
`https://downloads.wordpress.org/plugin/tassyir-for-woocommerce.<version>.zip`. Sites still on the
self-hosted zip then update to the wordpress.org build, which has no self-updater, and from then on
take updates only from wordpress.org.

## 6. Every release from now on

1. Bump the version in **four** places to the same number, e.g. `1.0.1`:
   - `tassyir-for-woocommerce.php`: the `Version:` header and `TASSYIR_WC_VERSION`;
   - `uninstall.php`: `TASSYIR_WC_VERSION`;
   - `readme.txt`: `Stable tag:`, plus a new `= 1.0.1 =` changelog entry.
   
   Also update `Tested up to` / `WC tested up to` (readme and plugin header) when a new WordPress or
   WooCommerce major is out and tested.
2. Run the checks (PHPCS, PHPStan, unit and integration tests; see `README.md`), then build both zips:
   `bin/build.sh` and `bin/build.sh --wporg`.
3. **Self-hosted:**
   - Upload `dist/tassyir-for-woocommerce-1.0.1.zip` where `WOO_PLUGIN_DOWNLOAD_URL` points.
   - Set `WOO_PLUGIN_LATEST_VERSION=1.0.1` and `WOO_PLUGIN_RELEASED_AT`.
   - Raise `WOO_PLUGIN_MIN_VERSION` only when old versions must stop working.
4. **wordpress.org:** refresh `trunk/` from the `-wporg` zip, then `svn cp trunk tags/1.0.1` and
   `svn ci`, as in step 4 (steps 2, 4, 5, 6 there).
5. Translations: once the plugin is listed, import `languages/*.po` into translate.wordpress.org
   (Arabic and French), or ask the language teams. When language packs exist, the
   `load_plugin_textdomain()` call can go in a later release, which also clears the one Plugin
   Check warning.
