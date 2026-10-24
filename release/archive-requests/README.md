# SOKNA Release visibility policy (2026-10-24)

Only the active **Windows Services**, **Prerequisites**, and **Maintenance Only**
release families should be publicly listed in this repository.

- Retain the newest qualified full (stable) release *where one exists* and the
  newest pre-release *where one exists* for each family.
- Earlier published releases are converted to **GitHub Draft releases**. This
  deliberately preserves Git tags, release assets, provenance, checksums and
  the ability of repository maintainers to republish one for rollback.
- This is **not** a repository archive, a git branch delete, or permanent release
  deletion. Draft release download URLs are not public; republish before relying
  on an older download for recovery.
- Do not invent a new stable release just to fill a slot: UAT pre-releases remain
  pre-releases until independently qualified on Home and Cafe.
- Do not touch the separate SOKNA-Bridge repository.
- A request JSON explicitly lists each existing release ID and tag to hide and
  the four retained public tags. `.github/workflows/archive-obsolete-sokna-releases.yml`
  refuses new, missing or unexpected tags and checks new integrated installer
  availability before mutating any release.
- Before each future archive, review any pinned artifact links that will become
  non-public.

Last known retained tags (subject to successful Windows 1.1.24 publication):
- `windows-services-v1.0.17` (stable)
- `windows-services-v1.1.24-rc.1` (pre-release)
- `prerequisites-v1.0.13` (stable)
- `maintenance-only-v0.3.15-uat.rev1` (pre-release)

Prerequisites does not yet have a pre-release and Maintenance Only does not yet
have a stable release. Neither is fabricated.
