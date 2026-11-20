# WP Bulk Media Import

Imports images from a folder on your server straight into the Media Library — the missing piece after an FTP upload, a host migration, or a bulk download. No re-uploading through the browser, no XML export/import dance.

## How it works

1. Go to **Tools → Bulk Media Import** and enter the absolute server path (e.g. `/var/www/example.com/wp-content/uploads/import/`).
2. The plugin scans the folder (subfolders included by default) and shows you exactly what it found.
3. Click **Start Import**. Files are imported in batches of 25, so a folder with thousands of images won't time out — each batch redirects to the next until the summary appears.

## Safety

- **Duplicate detection by content** — each file's md5 hash is compared against the library, so the same photo under a different filename is skipped instead of imported twice.
- **Source folder must live inside your WordPress install** (or uploads directory) — paths outside it are refused. The uploads folder itself is refused too, since importing from it would re-import your whole library.
- **Delete-after-import is opt-in** and off by default; source files are left alone unless you tick the box.
- Imported files are copied into the normal uploads year/month structure with `wp_unique_filename()`, and full attachment metadata (thumbnails, sizes) is generated via `wp_generate_attachment_metadata()`.
- Import state lives in a 1-hour transient; an expired job just asks you to scan again.

## Filters

- `wbmi_allowed_roots` — extra allowed source roots (real paths).
- `wbmi_image_extensions` — file extensions to import (default `jpg`, `jpeg`, `png`, `gif`, `webp`).

## Requirements

- WordPress 5.8+
- PHP 7.4+

## License

GPL-2.0-or-later — see [LICENSE](LICENSE).
