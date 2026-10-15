#!/usr/bin/env bash
# =============================================================================
# Smart Campus — TLS certificate setup for the pilot/production gateway
#
# The gateway (docker-compose.pilot.yml + infrastructure/pilot/nginx) serves
# HTTPS with ${PILOT_CERTS_DIR}/fullchain.pem and privkey.pem. This script
# puts a valid certificate there, for each hosting model (ACR-COM-01, D13):
#
#   status                          show the installed certificate
#   import <fullchain> <privkey>    institution's own certificate (on-premises)
#   internal-ca <name> [name...]    private CA for a campus LAN without public
#                                   DNS; names are DNS names or IP addresses.
#                                   AI nodes must trust ${CA_DIR}/ca.crt
#   letsencrypt <domain> <email> --agree-tos
#                                   public certificate for a vendor-hosted
#                                   instance (port 80 reachable from the
#                                   Internet; --agree-tos accepts the Let's
#                                   Encrypt Subscriber Agreement)
#   renew                           renew Let's Encrypt certificates (cron)
#
# Every install validates the pair (key matches, not expired) and reloads
# nginx when the gateway is running. Private keys never leave the host and
# are written with mode 600.
#
# Environment:
#   PILOT_CERTS_DIR   default infrastructure/pilot/certs
#   CA_DIR            default infrastructure/pilot/ca        (gitignored)
#   LE_DIR            default infrastructure/pilot/letsencrypt (gitignored)
#   ACME_DIR          default infrastructure/pilot/acme      (gitignored)
#   COMPOSE           default "docker compose -f docker-compose.yml -f docker-compose.pilot.yml"
# =============================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"

CERTS_DIR="${PILOT_CERTS_DIR:-infrastructure/pilot/certs}"
CA_DIR="${CA_DIR:-infrastructure/pilot/ca}"
LE_DIR="${LE_DIR:-infrastructure/pilot/letsencrypt}"
ACME_DIR="${ACME_DIR:-infrastructure/pilot/acme}"
COMPOSE="${COMPOSE:-docker compose -f docker-compose.yml -f docker-compose.pilot.yml}"
CERTBOT_IMAGE="certbot/certbot:v2.11.0"
SERVER_DAYS=397

die() { echo "ERROR: $*" >&2; exit 1; }

# Run openssl quietly, but show its own error output if it fails.
ossl() {
    local log
    log="$(mktemp)"
    if ! openssl "$@" 2>"$log"; then
        cat "$log" >&2
        rm -f "$log"
        die "openssl $1 failed"
    fi
    rm -f "$log"
}

need_openssl() {
    command -v openssl >/dev/null 2>&1 || die "openssl is required"
}

usage() {
    sed -n '4,20p' "$0" | sed 's/^# \{0,1\}//'
    exit 1
}

# validate <cert> <key>: key matches, certificate currently valid
validate_pair() {
    local cert="$1" key="$2"
    [ -s "$cert" ] || die "certificate not found: $cert"
    [ -s "$key" ] || die "private key not found: $key"
    openssl x509 -in "$cert" -noout 2>/dev/null || die "not a PEM certificate: $cert"
    local cert_pub key_pub
    cert_pub="$(openssl x509 -in "$cert" -noout -pubkey | openssl sha256)"
    key_pub="$(openssl pkey -in "$key" -pubout 2>/dev/null | openssl sha256)" || die "unreadable private key: $key"
    [ "$cert_pub" = "$key_pub" ] || die "the private key does not match the certificate"
    openssl x509 -in "$cert" -noout -checkend 0 >/dev/null || die "the certificate has expired"
    if ! openssl x509 -in "$cert" -noout -checkend $((14 * 86400)) >/dev/null; then
        echo "WARNING: the certificate expires within 14 days." >&2
    fi
}

reload_gateway() {
    if ${COMPOSE} ps --status running --services 2>/dev/null | grep -qx nginx; then
        ${COMPOSE} exec -T nginx nginx -t >/dev/null 2>&1 || die "nginx rejected the new certificate; the old one is still served"
        ${COMPOSE} exec -T nginx nginx -s reload
        echo "Gateway reloaded."
    else
        echo "Gateway not running; the certificate is used at the next start."
    fi
}

install_pair() {
    local cert="$1" key="$2"
    validate_pair "$cert" "$key"
    mkdir -p "$CERTS_DIR"
    umask 077
    cp "$key" "$CERTS_DIR/privkey.pem.new"
    cp "$cert" "$CERTS_DIR/fullchain.pem.new"
    chmod 600 "$CERTS_DIR/privkey.pem.new"
    chmod 644 "$CERTS_DIR/fullchain.pem.new"
    mv -f "$CERTS_DIR/privkey.pem.new" "$CERTS_DIR/privkey.pem"
    mv -f "$CERTS_DIR/fullchain.pem.new" "$CERTS_DIR/fullchain.pem"
    echo "Installed certificate into $CERTS_DIR."
    show_status
    reload_gateway
}

show_status() {
    local cert="$CERTS_DIR/fullchain.pem"
    [ -s "$cert" ] || { echo "No certificate installed in $CERTS_DIR."; return 1; }
    local end_date end_epoch days
    openssl x509 -in "$cert" -noout -subject -issuer
    openssl x509 -in "$cert" -noout -ext subjectAltName 2>/dev/null | tail -n +2 | sed 's/^ */SAN: /'
    end_date="$(openssl x509 -in "$cert" -noout -enddate | cut -d= -f2)"
    end_epoch="$(date -d "$end_date" +%s 2>/dev/null || echo 0)"
    if [ "$end_epoch" -gt 0 ]; then
        days=$(( (end_epoch - $(date +%s)) / 86400 ))
        echo "Expires: $end_date ($days days left)"
    else
        echo "Expires: $end_date"
    fi
}

san_list() {
    local out="" n
    for n in "$@"; do
        if [[ "$n" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] || [[ "$n" == *:* ]]; then
            out="${out:+$out,}IP:$n"
        else
            out="${out:+$out,}DNS:$n"
        fi
    done
    printf '%s' "$out"
}

internal_ca() {
    [ "$#" -ge 1 ] || die "usage: $0 internal-ca <dns-name-or-ip> [more names...]"
    need_openssl
    mkdir -p "$CA_DIR"
    umask 077
    if [ ! -s "$CA_DIR/ca.key" ]; then
        echo "Creating the campus internal CA in $CA_DIR (keep ca.key offline-backed-up)..."
        ossl req -x509 -newkey rsa:3072 -nodes -sha256 -days 3650 \
            -keyout "$CA_DIR/ca.key" -out "$CA_DIR/ca.crt" \
            -subj "/O=Smart Campus/CN=Smart Campus Internal CA $(date +%Y%m%d)" \
            -addext "basicConstraints=critical,CA:TRUE,pathlen:0" \
            -addext "keyUsage=critical,keyCertSign,cRLSign"
        chmod 600 "$CA_DIR/ca.key"
        chmod 644 "$CA_DIR/ca.crt"
    else
        echo "Using the existing internal CA in $CA_DIR."
    fi

    local work
    work="$(mktemp -d)"
    trap 'rm -rf "$work"' RETURN
    ossl req -newkey rsa:2048 -nodes -sha256 \
        -keyout "$work/server.key" -out "$work/server.csr" \
        -subj "/O=Smart Campus/CN=$1"
    cat > "$work/ext.cnf" <<EOF
basicConstraints=critical,CA:FALSE
keyUsage=critical,digitalSignature,keyEncipherment
extendedKeyUsage=serverAuth
subjectAltName=$(san_list "$@")
EOF
    ossl x509 -req -in "$work/server.csr" -CA "$CA_DIR/ca.crt" -CAkey "$CA_DIR/ca.key" \
        -CAcreateserial -days "$SERVER_DAYS" -sha256 -extfile "$work/ext.cnf" \
        -out "$work/server.crt"
    cat "$work/server.crt" "$CA_DIR/ca.crt" > "$work/fullchain.pem"
    install_pair "$work/fullchain.pem" "$work/server.key"

    echo
    echo "Next: give $CA_DIR/ca.crt (public, not the key) to every AI node as"
    echo "  certs/backend-ca.crt  and set SSL_CERT_FILE=/certs/backend-ca.crt in its .env."
    echo "Browsers on staff computers must also trust ca.crt (or show a warning)."
    echo "Renew before $(openssl x509 -in "$CERTS_DIR/fullchain.pem" -noout -enddate | cut -d= -f2) by re-running this command."
}

letsencrypt() {
    [ "$#" -eq 3 ] && [ "$3" = "--agree-tos" ] || die "usage: $0 letsencrypt <domain> <email> --agree-tos"
    local domain="$1" email="$2"
    mkdir -p "$LE_DIR" "$ACME_DIR"
    docker run --rm \
        -v "$ROOT/$LE_DIR:/etc/letsencrypt" \
        -v "$ROOT/$ACME_DIR:/var/www/acme" \
        "$CERTBOT_IMAGE" certonly --webroot -w /var/www/acme \
        -d "$domain" --email "$email" --agree-tos --non-interactive --keep-until-expiring
    install_letsencrypt "$domain"
    echo "Add a daily renewal, e.g. crontab: 17 3 * * * cd $ROOT && scripts/tls-setup.sh renew >> storage-tls-renew.log 2>&1"
}

install_letsencrypt() {
    local live="$LE_DIR/live/$1"
    [ -d "$live" ] || die "no Let's Encrypt certificate for $1 in $LE_DIR"
    # live/ holds symlinks into archive/: resolve them on the host side.
    install_pair "$(readlink -f "$live/fullchain.pem")" "$(readlink -f "$live/privkey.pem")"
}

renew() {
    [ -d "$LE_DIR/live" ] || die "no Let's Encrypt certificates to renew in $LE_DIR"
    docker run --rm \
        -v "$ROOT/$LE_DIR:/etc/letsencrypt" \
        -v "$ROOT/$ACME_DIR:/var/www/acme" \
        "$CERTBOT_IMAGE" renew --webroot -w /var/www/acme --non-interactive
    local d
    for d in "$LE_DIR"/live/*/; do
        [ -f "$d/fullchain.pem" ] && install_letsencrypt "$(basename "$d")"
    done
}

[ "$#" -ge 1 ] || usage
cmd="$1"; shift
case "$cmd" in
    status) need_openssl; show_status ;;
    import) [ "$#" -eq 2 ] || die "usage: $0 import <fullchain.pem> <privkey.pem>"; need_openssl; install_pair "$1" "$2" ;;
    internal-ca) internal_ca "$@" ;;
    letsencrypt) need_openssl; letsencrypt "$@" ;;
    renew) need_openssl; renew ;;
    *) usage ;;
esac
