# Release notes (ACR-OPS-01)

Write one file per release, named exactly like the tag: `docs/releases/v1.2.0.md`. Copy `TEMPLATE.md`, write in
Thai for school administrators (what changed for them, not commit messages), commit it **before** pushing the tag.

`.github/workflows/release.yml` uses the file as the GitHub Release body; without one it falls back to GitHub's
generated notes (PR titles, usually English). The web page **อัปเดตระบบ** renders the body with a safe Markdown subset:
`#`/`##`/`###` headings, `-` lists, paragraphs, `**bold**`, `` `code` ``, fenced code. Raw HTML and links are not
rendered (links show their text only).

Notes for a release that is already published can be changed on GitHub (Edit release); servers pick the change up at
the next daily check or "ตรวจหารุ่นใหม่".
