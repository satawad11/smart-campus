# TLS setup (HTTPS for the campus instance)

Every real installation serves the web console, the API and the AI node API over
HTTPS only. The pilot/production gateway (`docker-compose.pilot.yml`) listens on 443,
redirects HTTP to HTTPS, and reads `fullchain.pem` + `privkey.pem` from
`infrastructure/pilot/certs/` (`PILOT_CERTS_DIR`). `scripts/tls-setup.sh` puts a
validated certificate there and reloads the gateway.

Pick the case that matches the hosting model (ACR-COM-01, decision D13):

| Case | Command | Who trusts it |
|---|---|---|
| Institution already has a certificate (on-premises) | `scripts/tls-setup.sh import fullchain.pem privkey.pem` | Everyone already trusting the institution's CA |
| Campus LAN, no public DNS (on-premises) | `scripts/tls-setup.sh internal-ca smartcampus.school.local 10.0.0.20` | Only devices given `infrastructure/pilot/ca/ca.crt` |
| Vendor-hosted (cloud), public domain | `scripts/tls-setup.sh letsencrypt campus1.example.com ops@example.com --agree-tos` | All browsers and nodes |

`scripts/tls-setup.sh status` shows the subject, names and days left.

## Rules

- The private key stays on the host (mode 600, git-ignored). Never e-mail it or
  copy it into a ticket.
- `import` refuses a key that does not match the certificate, or an expired
  certificate, and warns when fewer than 14 days are left.
- List every name and IP that clients use in the certificate. A node configured
  with `https://10.0.0.20` needs `10.0.0.20` in the certificate.
- `pilot-host-preflight.sh` check H1 must pass (trusted chain, ≥ 14 days).

## Internal CA (campus LAN)

- `internal-ca` creates the CA once (`ca.key`, valid 10 years) and a server
  certificate valid 397 days. Re-run the same command to renew; the CA is reused.
- **Back up `infrastructure/pilot/ca/ca.key` offline** (two encrypted copies). If it
  is lost, every node and browser must be given a new CA.
- AI nodes: copy `ca.crt` (public) to the node as `certs/backend-ca.crt` and set
  `SSL_CERT_FILE=/certs/backend-ca.crt` in the node `.env`, then `./start.sh`.
  Verified: the node client connects with it and refuses the server without it.
- Staff computers: install `ca.crt` as a trusted root (Windows: certmgr → Trusted Root
  Certification Authorities; or by Group Policy).
- The internal CA publishes no revocation list. Browsers accept that; Windows
  `curl` needs `--ssl-no-revoke`. To revoke a compromised server key, issue a new
  certificate; to revoke the CA, create a new CA and redistribute it.

## Let's Encrypt (vendor-hosted)

- Requirements: the domain's DNS points to the instance, and port 80 is reachable
  from the Internet (the gateway serves `/.well-known/acme-challenge/` on port 80;
  everything else still redirects to HTTPS).
- Passing `--agree-tos` means the operator accepts the Let's Encrypt Subscriber
  Agreement for that domain.
- Certificates last 90 days. Schedule a daily renewal on the host:
  `17 3 * * * cd /opt/smart-campus && scripts/tls-setup.sh renew`
  `renew` only replaces certificates that are due, then reloads the gateway.
- AI nodes need no CA file (leave `SSL_CERT_FILE` unset).

## Not verified yet

The internal-CA and import paths were exercised on a development machine
(certificate creation, mismatched-key rejection, nginx serving the pilot config,
the node's HTTP client with and without the CA). The Let's Encrypt path needs a
public domain and has not been run.
