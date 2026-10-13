# Changelog

## 1.0.0 — 2026-10-13

First public release. Built for kamilari.org.gr and geotour.gr.

- **Groups explorer:** every group as a bubble, overlapping groups drawn to
  scale, a member panel, and members' portraits inside each circle.
- **Family tree** on family-chart: focus-centred, loads more as you click,
  highlights the chosen groups.
- **Person pages** with portrait, dates, places, notes, sources and
  relatives.
- **GEDCOM 5.5 import and export**, round-trip safe, with extension tags
  `_GROUP`, `_PERIOD`, `_ARTICLE` and `_STATUS`. An add-only mode extends a
  tree without changing it. The import page checks a file before saving
  anything.
- **Collections:** several unrelated family networks on one site.
- **Related article** per person, with a `[genoroots_family_link]` link
  back from the article.
- **Conversion from Genealogical Tree** (`wp genoroots import-legacy`), with
  dry runs, rehearsals and 301 redirects for the old addresses.
- **Bundles** for moving data between sites: export, import, verify.
- **Optional Piwigo connector:** links people to their photos by the
  number in the tag name.
- **English and Greek** interface; dates shown in the page language.
- **Address words** are a setting (GenoRoots → Settings → Addresses).
  New installs use `/family-tree/`, `/person/`, `/family-group/` and
  `/collection/`. Sites that ran an earlier version keep `/mele/`,
  `/melos/`, `/oikogeneia/` and `/syllogi/`.
- **Privacy:** draft people are left out of the tree and of the relatives
  shown on published people's pages.
- Licensed GPL-2.0-or-later.
