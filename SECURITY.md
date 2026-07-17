# Security policy

## Supported versions

Security fixes are provided for the latest minor release. Operators should run
the newest tagged release and keep Omeka S, PHP, Composer dependencies,
Typesense, and the web server patched.

## Reporting a vulnerability

Do not open a public issue for a suspected vulnerability. Use GitHub's private
vulnerability-reporting feature for this repository, or contact the University
of Bayreuth Africa Multiple DRE maintainers. Include the affected version,
reproduction steps, impact, and any suggested mitigation.

We will acknowledge a report, validate it, coordinate a fix and release, then
credit the reporter unless anonymity is requested.

## Security boundaries

- The Typesense API key remains server-side and is never included in bootstrap
  JSON or JavaScript.
- Public queries always add `is_public:=true`.
- Page-block scopes are reloaded from persisted block data by ID; client raw
  filter expressions are rejected.
- Editor-authored block HTML is sanitized. Highlights render as text nodes.
