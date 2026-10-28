# Security policy

## Reporting a vulnerability

Do not open a public issue. Use GitHub's private vulnerability reporting ("Report a vulnerability" under the
repository's Security tab) with a description, affected versions and, if you can, a proof of concept.

Direct link: <https://github.com/El-Hendawy/keel/security/advisories/new>.

## What to expect

| Step | Commitment |
| --- | --- |
| Acknowledgement | Within 72 hours |
| Assessment and severity | Within 7 days |
| Fix for critical and high severity | As fast as possible; target 14 days |
| Disclosure | Coordinated with the reporter; a GitHub security advisory and a CVE where applicable |
| Backports | To every supported major |

Reporters are credited in the advisory unless they ask not to be.

## Supported versions

| Version | Security fixes |
| --- | --- |
| 0.x (preview) | Latest 0.x minor only |
| 1.x and later | 3 years from the major's release |

## Secure by default

Defaults that matter for your threat model: unknown input fields are rejected, SQL must be literal
(`rawQuery()` is the named exception), forwarding headers are ignored unless you call `trustProxy()`, security
headers are added to every response, and production refuses to boot with debug on or without `app.key`.
With `->withAuth()`, every endpoint requires authentication unless marked `#[Anonymous]`, tokens are stored
hashed and bound to the client they were issued to, and SQL on tenant tables must filter by the current
tenant. Outgoing webhooks cannot target private networks. See [docs/guide/production.md](docs/guide/production.md)
and [docs/guide/auth.md](docs/guide/auth.md).
