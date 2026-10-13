# Moving from Genealogical Tree to GenoRoots

GenoRoots can take over a site that runs the
[Genealogical Tree](https://wordpress.org/plugins/genealogical-tree/) plugin
(post types `gt-member` and `gt-family`, groups in the taxonomy
`gt-family-group`). It was built and tested against Genealogical Tree Pro
2.2.8, on a live site with 59 people and 23 families.

The conversion reads the old plugin's rows straight from the database,
whether or not the old plugin is active, and leaves them untouched. Both
plugins can be active at the same time, so the old pages keep working until
you switch over.

## What is converted

| Genealogical Tree | GenoRoots |
|---|---|
| member post 384 | person with **GenoRoots ID `I384`** |
| family post 386 | family `F386`, with husband, wife and children re-linked |
| title, slug, status, date | the same, so `/gt-member/<slug>/` becomes `/person/<slug>/` |
| sex; birth, death and marriage events (date, place) | sex; birth, death and marriage date and place |
| `additional_info`, `note`, `additional_fields` | **Notes** |
| featured image | **portrait**: the same media file, not copied |
| `photo_archive_link` | **Photo archive link** |
| `id_tag` | **Piwigo tag ID** |
| `gt-family-group` terms and memberships | groups (skip with `--skip-groups`) |
| `phone`, `email`, `address` | **not copied**: private contact details |

The GenoRoots ID keeps the old post number on purpose. If you already use
those numbers elsewhere, e.g. in photo keywords like "Maria Papadaki [384]",
they stay valid. Don't renumber.

Running the conversion again is safe: records are matched by ID and
updated, never duplicated, and nothing is deleted.

## Before you start

- **Rehearse on a copy of the site first**, e.g. a local copy made with
  Local or a staging site. Every step below works the same there.
- WP-CLI access to the site (`wp --info` works).
- PHP 8.2 or newer, and Advanced Custom Fields active.
- Decide where edits happen afterwards. Once you switch, edit only in
  GenoRoots: running the conversion again overwrites the converted records
  with the old plugin's data.

## Step by step

1. **Back up the database:**
   ```bash
   wp db export ~/backup-before-genoroots.sql
   ```
2. **Install and activate GenoRoots** (see the [README](../README.md#install)).
   Leave Genealogical Tree active.
3. **Check the site:**
   ```bash
   wp genoroots preflight
   ```
   It should end with "Success". It checks PHP, ACF, permalinks, free
   addresses, and whether a page cache needs purging.
4. **See what would happen** (writes nothing):
   ```bash
   wp genoroots import-legacy --dry-run
   ```
5. **Rehearse:** the full conversion and a check, then everything is rolled
   back:
   ```bash
   wp genoroots import-legacy --rehearse
   ```
6. **Convert**, and keep a copy of the converted data to verify against:
   ```bash
   wp genoroots import-legacy --save-bundle=~/legacy.json
   wp genoroots verify-bundle ~/legacy.json
   ```
   `verify-bundle` compares the site with the file record by record and
   should report that they match.
7. **Preview:** make a private page with `[genoroots_app]` (full-width
   layout) and compare it with the old trees.
8. **Switch over:**
   - point your menu at the new page, or at `/family-tree/`
   - publish the page
   - deactivate Genealogical Tree
9. **Flush and purge:** `wp rewrite flush`, then clear any page cache.
10. **Check** the explorer, a group, a person's tree and a person page.
11. **Delete `~/legacy.json`** from the server: it holds personal data.

## Old addresses

Once Genealogical Tree is deactivated, its addresses redirect (301):

- `/gt-member/<slug>/` → the person's GenoRoots page
- `/gt-family-group/<slug>/` → the explorer with that group selected
- anything else of the old plugin → the explorer

## Choosing groups

The conversion brings over the old plugin's groups. To use a different set,
e.g. groups you curated on another GenoRoots site:

```bash
wp genoroots import-legacy --skip-groups --save-bundle=~/legacy.json
# groups only, from a bundle made on the other site with export-bundle
wp genoroots import-bundle ~/curated.json --only-groups --dry-run
wp genoroots import-bundle ~/curated.json --only-groups
wp genoroots verify-bundle ~/curated.json --only-groups
```

## Photos in Piwigo

If the old plugin's `id_tag` pointed at Piwigo tags, those tag numbers come
across as they were. Piwigo renumbers tags when a gallery is rebuilt, so run
the sync once after converting. It matches people by the `[N]` in the tag
name and replaces stale numbers:

```bash
wp genoroots piwigo-sync --piwigo=https://pics.example.org --dry-run
wp genoroots piwigo-sync --piwigo=https://pics.example.org
```

## Rolling back

- **Hide it:** deactivate GenoRoots and reactivate Genealogical Tree.
  Neither plugin's data is touched.
- **Undo completely:** `wp db import ~/backup-before-genoroots.sql`.
