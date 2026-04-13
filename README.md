# Hungry Nuggets WordPress Toolkit

A lightweight WordPress plugin that converts images to **WebP** (or AVIF) on upload, with configurable quality, max size, and format — all from **Settings > Media**.

Built and maintained by [Hungry Nuggets](https://hungrynuggets.com) for internal and client WordPress sites.

---

## Features

- **Format conversion** — JPEG, PNG, GIF → WebP or AVIF on upload. The original file is replaced.
- **Alpha channel preservation** — PNG with transparency is converted via GD to preserve the alpha channel correctly.
- **Configurable via admin** — Format, quality (%), and max dimension (px) are set in Settings > Media.
- **MIME type bug workaround** — Correctly detects image type via file extension, bypassing a WP 6.9.x bug that misdetects JPEG as `image/avif`.
- **Thumbnail only** — Only the `thumbnail` intermediate size (150×150 crop) is generated. Medium, medium-large, and large sub-sizes are suppressed.
- **Auto-update** — The plugin checks GitHub Releases every 12 hours and appears in the standard WordPress update flow.

---

## Requirements

- PHP 8.0+
- WordPress 6.0+
- GD extension (for WebP — always available on Plesk/standard hosts)
- Imagick with libavif (only required if AVIF format is selected)

---

## Installation

### Via WP-CLI (recommended for Hungry Nuggets servers)

```bash
# Install latest release
wp plugin install https://github.com/thomasgermain93/hn-wordpress-toolkit/releases/latest/download/hn-wordpress-toolkit-<VERSION>.zip --activate --allow-root

# Or: force-update to latest
wp plugin install https://github.com/thomasgermain93/hn-wordpress-toolkit/releases/latest/download/hn-wordpress-toolkit-<VERSION>.zip --force --activate --allow-root
```

> Replace `<VERSION>` with the actual version number (e.g. `1.0.0`).  
> To find the latest ZIP URL: `gh release view --repo thomasgermain93/hn-wordpress-toolkit --json assets -q '.assets[].browserDownloadUrl'`

### Via WordPress admin

1. Download the latest `.zip` from [Releases](https://github.com/thomasgermain93/hn-wordpress-toolkit/releases).
2. Go to **Plugins > Add New > Upload Plugin**.
3. Upload the ZIP and activate.

---

## Configuration

Go to **Settings > Media**. The plugin adds an **"Optimisation des images"** section with three fields:

| Setting | Default | Description |
|---------|---------|-------------|
| Format de conversion | `WebP` | Target format. AVIF requires Imagick + libavif. |
| Qualité de compression | `90` | Compression quality (1–100). Recommended: 85–92. |
| Taille max (px) | `2000` | Max width or height. Images are resized proportionally before conversion. |

---

## Auto-updates

The plugin checks `https://api.github.com/repos/thomasgermain93/hn-wordpress-toolkit/releases/latest` every 12 hours and injects the result into the WordPress update transient.

When a new version is available:
- It appears in **Dashboard > Updates** like any plugin.
- It can be updated via WP-CLI: `wp plugin update hn-wordpress-toolkit --allow-root`

---

## Deploying a new version

```bash
# Bump version in hn-wordpress-toolkit.php (HN_IMG_OPT_VERSION + Plugin header)
git commit -am "chore: bump version to 1.x.x"
git tag v1.x.x
git push origin main --tags
```

GitHub Actions will automatically build and attach the ZIP to the release.

---

## Technical reference (for AI assistants)

### WordPress options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `hn_img_format` | string | `webp` | `'webp'` or `'avif'` |
| `hn_img_quality` | int | `90` | Compression quality 1–100 |
| `hn_img_maxsize` | int | `2000` | Max dimension in pixels |

### PHP functions

| Function | Returns | Description |
|----------|---------|-------------|
| `hn_img_format()` | `string` | Returns current format option |
| `hn_img_quality()` | `int` | Returns current quality option |
| `hn_img_maxsize()` | `int` | Returns current maxsize option |
| `hn_img_resize_gd(GdImage, int)` | `GdImage` | Proportional resize with alpha channel preservation |
| `hn_img_via_editor(string, string, string, int, int)` | `bool` | Convert via `wp_get_image_editor` (JPEG/GIF → WebP) |

### Hooks

| Hook | Priority | Action |
|------|----------|--------|
| `wp_handle_upload` | 5 | Convert image to target format, update `file`/`url`/`type` |
| `intermediate_image_sizes_advanced` | default | Return only `thumbnail` size |
| `jpeg_quality` | default | Return `hn_img_quality()` |
| `wp_editor_set_quality` | default | Return `hn_img_quality()` |
| `admin_init` | default | Register settings in `media` option group |

### Conversion logic

```
wp_handle_upload (priority 5)
├── Resolve actual MIME from file extension (WP 6.9 bug workaround)
├── Skip if not JPEG / PNG / GIF
├── format = webp?
│   ├── type = image/png?  → GD: imagecreatefrompng → hn_img_resize_gd → imagewebp
│   └── else               → hn_img_via_editor (wp_get_image_editor → save as image/webp)
├── format = avif?
│   └── Imagick: resizeImage → setImageFormat('avif') → writeImage
└── If converted: unlink original, update $upload['file'/'url'/'type']
```

### Update mechanism

`HN_Image_Optimizer_Updater` (in `includes/class-updater.php`):
- Hooks into `pre_set_site_transient_update_plugins`
- Calls `https://api.github.com/repos/thomasgermain93/hn-wordpress-toolkit/releases/latest`
- Caches response in `hn_img_opt_github_release` transient for 12 hours (30 min on error)
- Injects update into WordPress transient if `tag_name` version > installed version
- ZIP URL: first `.zip` asset in the release, fallback to `zipball_url`

---

## License

MIT — free to use, modify, and deploy on any client site.
