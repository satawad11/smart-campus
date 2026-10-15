# Pilot TLS certificates (not committed)

The gateway reads these files on the pilot/production host:

- `fullchain.pem` — certificate + intermediate chain for the host name(s)
- `privkey.pem`   — private key (mode 0600, owned by the deploy user)

Install them with `scripts/tls-setup.sh` (import, internal CA or Let's Encrypt);
see `docs/commercial/TLS_SETUP.md`.

`*.pem` is git-ignored. Never commit certificates or keys.
