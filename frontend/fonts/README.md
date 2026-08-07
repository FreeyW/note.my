# Self-hosted fonts

Place `dm-sans.woff2` and `dm-mono.woff2` here. `scripts/build.sh` copies them
into `public/assets/fonts/`.

They are committed to the repository rather than fetched from Google Fonts:
`font-src 'self'` in the CSP forbids the request outright, and a font fetched
per page load would tell a third party the exact moment someone opened a note.

Both faces are SIL Open Font License 1.1. Download the woff2 subsets from
<https://fonts.google.com/specimen/DM+Sans> and
<https://fonts.google.com/specimen/DM+Mono>, or build them with `fonttools`.
