# Security policy

## Reporting a vulnerability

Do not open a public issue. Use GitHub's private vulnerability reporting ("Report a vulnerability" under the
repository's Security tab) with a description, affected versions and, if you can, a proof of concept.

> Maintainers: before the first public release, enable private vulnerability reporting on the repository and
> add a dedicated security email address here.

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
| 0.1.x (preview) | Latest 0.x minor only |
| 1.x and later | 3 years from the major's release |

## Secure by default

Defaults that matter for your threat model: unknown input fields are rejected, SQL must be literal
(`rawQuery()` is the named exception), forwarding headers are ignored unless you call `trustProxy()`, security
headers are added to every response, and production refuses to boot with debug on. See
[docs/guide/production.md](docs/guide/production.md).
