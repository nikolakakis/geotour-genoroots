# Changelog

## 1.3.0 — 2026-11-22

- **Unknown generations (placeholders):** link two known people when the
  people between them were never recorded: a grandson whose father is not
  named, a family's claimed descent from a founder ten generations back, or
  two relatives whose kinship is unknown (an unknown shared ancestor). A
  placeholder has a span of generations (e.g. 1 or 8–12), a basis (records,
  family tradition, forged charter, coins, surname only, other) and a note;
  its name may stay empty and shows as "Unknown".
- **In the tree** a placeholder is a dashed "?" card with its span and
  basis, and the lines through it are dotted. Its panel shows the note.
- **Claimed links look claimed:** a family whose Certainty is "claimed" is
  drawn with dashed amber lines, and the descendant's card gets a "claimed"
  tag; its panel says "Descent claimed, not proven".
- **Placeholders count as nobody:** left out of group sizes and shared
  counts, member lists, search, the A–Z lists, sitemaps (WordPress, Yoast,
  Rank Math), the WordPress REST list of people and the "Family tree" card
  on articles. They have no public page. The tree still draws through them.
- **Field editor:** "+ Unknown generations" on any person: choose ancestor,
  descendant or relative, find the other person, set span, basis, certainty
  and note, with a live preview. Placeholders open and edit in their own
  form. New REST route `POST /editor/gap`.
- **Admin:** "Unknown person (placeholder)" on the person screen, a
  "Placeholder" label in the list of people, and the span in the side box.
- **GEDCOM:** placeholders export as `NAME Unknown` with a `_PLACEHOLDER`
  block (span, `_BASIS`, note) and a plain-English `NOTE` for other
  programs; the import reads them back. Bundles carry them.
- `/focus` now returns each family's `status` and marks placeholders.
- Fixed: in the field editor, saving a person's details or creating a
  person left a broken screen (the answer carried the person's ID where the
  screen expected the person).

## 1.2.0 — 2026-10-15

- **Field editor** (`[genoroots_field_editor]`): edit the tree from a phone,
  without the admin. Find someone, see their parents, siblings, spouses and
  children, and add a spouse, child, parent or sibling: first by linking
  someone already in the tree, else by creating them. Creating someone whose
  name is already in the tree asks "Is it one of these?" first. Also: edit a
  person, marriage details, remove a link, take a portrait with the camera.
- **Edit from the tree:** people who may edit see "✎ Edit" in the tree's
  person panel and on person pages; it opens that person in the editor.
  Visitors see nothing new.
- **Portraits are cropped square on the phone:** take or choose a photo,
  drag, pinch or zoom, rotate a quarter turn. Only that square is uploaded,
  800 × 800 px JPEG, and WordPress keeps just the two sizes GenoRoots shows.
- **Every edit can be undone.** Changes made in the field editor and in the
  admin Relations box are logged with what they changed. History lists
  them, and Undo puts the records back, unless someone has changed them
  since. An undo can be redone. `wp genoroots changes list|undo`.
- **Kept when the connection drops:** a change that can't be sent stays on
  the phone ("1 change not sent") and goes out when the phone is back
  online. Sending twice never saves twice. Unfinished forms are kept too.
- **Genealogy editors use the field editor:** after signing in, and instead
  of the admin (GenoRoots → Settings → Field editor turns this off). An
  administrator can limit an editor to some collections on their profile.
- **Father's name and nickname** for each person: shown on the person page,
  in the tree panel and in search results, searchable, and exported as
  GEDCOM `NICK` and `_PATR`.
- **Search** finds people by name, name in Latin characters, father's name,
  nickname, or GenoRoots number ("384"). Every word must match.
- Editing two people at once from two phones is safe: one save waits for
  the other, and a form opened on old details asks you to check again.

## 1.1.0 — 2026-10-14

- **Genealogy editor role:** edits and publishes people and families, edits
  relations, uploads portraits and puts people into existing groups. It
  can't delete people, create or change groups, or reach anything else in
  the admin.
- **GenoRoots has its own permissions** (`*_genoroots_people`,
  `manage_genoroots_groups`), so role plugins can hand out tree access
  separately from blog posts. Administrators and Editors get them all on
  update, so nothing changes for them. Authors and Contributors no longer
  edit people through their post permissions.

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
