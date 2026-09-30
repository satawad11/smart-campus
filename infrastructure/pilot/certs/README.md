# Pilot TLS certificates (not committed)

Place the pilot gateway certificate here on the pilot host:

- `fullchain.pem` — certificate + intermediate chain for the pilot DNS name
- `privkey.pem`   — private key (mode 0600, owned by the deploy user)

`*.pem` is git-ignored. Never commit certificates or keys.
