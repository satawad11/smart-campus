#!/usr/bin/env bash
# AI Smart Campus — day-to-day commands for a server installed from the kit.
# Installed as /usr/local/bin/smart-campus by install.sh.
set -euo pipefail

INSTALL_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")" && pwd)"
cd "${INSTALL_DIR}"
export BACKEND_ENV_FILE=./campus.env
DC=(docker compose --env-file ./campus.env -f docker-compose.yml -f docker-compose.pilot.yml -f docker-compose.kit.yml)

# ---- update from GitHub Releases (manual, admin-triggered) -----------------
# Releases are built by .github/workflows/release.yml after CI passes and
# carry campus-server-kit-<tag>-<arch>.tar.gz plus a .sha256 file. The server
# only downloads and verifies the kit; install.sh does the update (verified
# backup first, rollback images, migrations, restart).
UPDATE_ENV="${INSTALL_DIR}/update.env"
RELEASES_DIR="${INSTALL_DIR}/releases"
GITHUB_API="${GITHUB_API:-https://api.github.com}"

fail() { printf '\033[31m%s\033[0m\n' "$*" >&2; exit 1; }

need_root() { [ "$(id -u)" -eq 0 ] || fail "ต้องรันด้วย sudo"; }

load_update_env() {
    [ -f "${UPDATE_ENV}" ] || fail "ยังไม่ได้ตั้งค่าการอัปเดต: รัน sudo smart-campus update-setup ก่อน"
    # shellcheck disable=SC1090
    . "${UPDATE_ENV}"
    [ -n "${GITHUB_REPO:-}" ] || fail "update.env ไม่มี GITHUB_REPO"
    command -v curl >/dev/null || fail "ต้องมีคำสั่ง curl"
    command -v python3 >/dev/null || fail "ต้องมี python3 (ใช้อ่านข้อมูลจาก GitHub)"
    ARCH="$(cat ARCH 2>/dev/null || echo amd64)"
}

gh_api() { # gh_api <path>: GET a GitHub REST resource as JSON
    local auth=()
    [ -n "${GITHUB_TOKEN:-}" ] && auth=(-H "Authorization: Bearer ${GITHUB_TOKEN}")
    curl -fsS --max-time 60 "${auth[@]}" -H "Accept: application/vnd.github+json" \
        -H "X-GitHub-Api-Version: 2022-11-28" "${GITHUB_API}$1"
}

gh_download() { # gh_download <asset id> <file>: private assets need the API URL + octet-stream
    local auth=()
    [ -n "${GITHUB_TOKEN:-}" ] && auth=(-H "Authorization: Bearer ${GITHUB_TOKEN}")
    # curl drops the Authorization header when GitHub redirects to its storage host.
    curl -fL --retry 3 --progress-bar "${auth[@]}" -H "Accept: application/octet-stream" \
        "${GITHUB_API}/repos/${GITHUB_REPO}/releases/assets/$1" -o "$2"
}

release_field() { # release_field <tag_name|body> < release json
    python3 -c 'import json, sys; print(json.load(sys.stdin).get(sys.argv[1]) or "")' "$1"
}

asset_id() { # asset_id <file name> < release json: id of that release asset, empty if absent
    python3 -c '
import json, sys
print(next((a["id"] for a in json.load(sys.stdin).get("assets", []) if a["name"] == sys.argv[1]), ""))' "$1"
}

update_setup() {
    need_root
    local repo token
    read -r -p "GitHub repository [${GITHUB_REPO:-satawad11/smart-campus}]: " repo
    repo="${repo:-${GITHUB_REPO:-satawad11/smart-campus}}"
    printf 'GitHub token แบบอ่านอย่างเดียว (Contents: Read-only) เว้นว่างถ้าเป็น repo สาธารณะ: '
    read -r -s token; printf '\n'
    umask 077
    printf 'GITHUB_REPO=%s\nGITHUB_TOKEN=%s\n' "${repo}" "${token}" > "${UPDATE_ENV}"
    chmod 600 "${UPDATE_ENV}"
    load_update_env
    if gh_api "/repos/${GITHUB_REPO}/releases?per_page=1" >/dev/null; then
        printf '\033[32mเชื่อมต่อ GitHub ได้\033[0m (บันทึกที่ %s, สิทธิ์ 600)\n' "${UPDATE_ENV}"
    else
        fail "เชื่อมต่อไม่ได้: ตรวจชื่อ repository, token (ต้องอ่าน Contents ได้) และอินเทอร์เน็ตของเครื่องนี้"
    fi
}

# Token-free state for the web app's System update page (ACR-OPS-01).
publish_update_state() {
    [ "$(id -u)" -eq 0 ] && [ -f "${INSTALL_DIR}/update-agent.py" ] || return 0
    python3 -I "${INSTALL_DIR}/update-agent.py" publish-state --install-dir "${INSTALL_DIR}" || true
}

update_check() {
    load_update_env
    local current releases
    current="$(cat VERSION 2>/dev/null || echo unknown)"
    releases="$(gh_api "/repos/${GITHUB_REPO}/releases?per_page=20")" || fail "อ่านรายการรุ่นจาก GitHub ไม่ได้"
    if [ "$(id -u)" -eq 0 ] && [ -f "${INSTALL_DIR}/update-agent.py" ]; then
        printf '%s' "${releases}" | python3 -I "${INSTALL_DIR}/update-agent.py" write-releases \
            --install-dir "${INSTALL_DIR}" --arch "${ARCH}"
        publish_update_state
    fi
    printf 'รุ่นที่ติดตั้ง: %s\n\nรุ่นบน GitHub (ล่าสุดก่อน):\n' "${current}"
    printf '%s' "${releases}" | python3 -c '
import json, sys
current, arch = sys.argv[1], sys.argv[2]
for r in json.load(sys.stdin):
    if r.get("draft"):
        continue
    kit = any(a["name"] == "campus-server-kit-%s-%s.tar.gz" % (r["tag_name"], arch) for a in r.get("assets", []))
    flags = []
    if r["tag_name"] == current: flags.append("ติดตั้งอยู่")
    if r.get("prerelease"): flags.append("ทดสอบ")
    if not kit: flags.append("ไม่มีชุดติดตั้ง")
    print("  %-24s %s  %s" % (r["tag_name"], r.get("published_at", "")[:10], " ".join("[%s]" % f for f in flags)))
' "${current}" "${ARCH}"
    printf '\nอัปเดต: sudo smart-campus update <รุ่น>   (ไม่ระบุ = รุ่นล่าสุดที่ไม่ใช่รุ่นทดสอบ)\n'
}

update_run() {
    need_root
    load_update_env
    local tag="" assume_yes=0 force=0 arg
    for arg in "$@"; do
        case "${arg}" in
            --yes|-y) assume_yes=1 ;;
            --force) force=1 ;;
            -*) fail "ไม่รู้จักตัวเลือก ${arg}" ;;
            *) tag="${arg}" ;;
        esac
    done

    local release
    if [ -z "${tag}" ] || [ "${tag}" = latest ]; then
        release="$(gh_api "/repos/${GITHUB_REPO}/releases/latest")" || fail "ไม่พบรุ่นล่าสุดบน GitHub"
    else
        release="$(gh_api "/repos/${GITHUB_REPO}/releases/tags/${tag}")" || fail "ไม่พบรุ่น ${tag} บน GitHub"
    fi
    tag="$(printf '%s' "${release}" | release_field tag_name)"
    local current kit kit_id sum_id
    current="$(cat VERSION 2>/dev/null || echo unknown)"
    if [ "${tag}" = "${current}" ] && [ "${force}" -eq 0 ]; then
        printf 'ติดตั้งรุ่น %s อยู่แล้ว (ใช้ --force เพื่อติดตั้งซ้ำ)\n' "${tag}"; return 0
    fi
    kit="campus-server-kit-${tag}-${ARCH}"
    kit_id="$(printf '%s' "${release}" | asset_id "${kit}.tar.gz")"
    sum_id="$(printf '%s' "${release}" | asset_id "${kit}.tar.gz.sha256")"
    [ -n "${kit_id}" ] && [ -n "${sum_id}" ] || fail "รุ่น ${tag} ไม่มีไฟล์ ${kit}.tar.gz และ .sha256"

    printf 'อัปเดตจาก %s เป็น %s\n' "${current}" "${tag}"
    printf '%s' "${release}" | release_field body | sed -n '1,15p' | sed 's/^/  │ /'
    if [ "${assume_yes}" -eq 0 ]; then
        local a; read -r -p "ระบบจะสำรองข้อมูลก่อน แล้วปิดให้บริการชั่วคราวระหว่างอัปเดต ดำเนินการต่อ? [y/N]: " a
        case "${a}" in y|Y|yes|YES) ;; *) printf 'ยกเลิก\n'; return 0 ;; esac
    fi

    mkdir -p "${RELEASES_DIR}"; chmod 700 "${RELEASES_DIR}"
    local free_gb; free_gb="$(df -Pk "${RELEASES_DIR}" | awk 'NR == 2 { printf "%d", $4 / 1024 / 1024 }')"
    [ "${free_gb}" -ge 5 ] || fail "พื้นที่ว่างเหลือ ${free_gb} GB (ต้องการอย่างน้อย 5 GB สำหรับดาวน์โหลดและแตกไฟล์)"

    cd "${RELEASES_DIR}"
    printf 'ดาวน์โหลด %s.tar.gz ...\n' "${kit}"
    gh_download "${kit_id}" "${kit}.tar.gz" || fail "ดาวน์โหลดไม่สำเร็จ"
    gh_download "${sum_id}" "${kit}.tar.gz.sha256" >/dev/null 2>&1 || fail "ดาวน์โหลดไฟล์ตรวจสอบไม่สำเร็จ"
    sha256sum -c "${kit}.tar.gz.sha256" >/dev/null || { rm -f "${kit}.tar.gz"; fail "SHA-256 ไม่ตรง: ไฟล์เสียหายหรือถูกแก้ไข ลบทิ้งแล้ว"; }
    printf '\033[32mตรวจ SHA-256 ผ่าน\033[0m\n'
    rm -rf "${kit}"
    tar -xzf "${kit}.tar.gz"
    rm -f "${kit}.tar.gz"
    [ -x "${kit}/install.sh" ] || fail "ไม่พบ install.sh ในชุดติดตั้ง"

    # Keep the two newest extracted kits (this one and the previous), drop older ones.
    ls -1dt "${RELEASES_DIR}"/campus-server-kit-*/ 2>/dev/null | tail -n +3 | xargs -r rm -rf

    # install.sh: verified backup, rollback image tags, maintenance mode,
    # migrations, restart, preflight; its own messages explain any failure.
    # exec, not a child process: install.sh replaces this very script
    # (campus.sh) in place, and bash must not read on from the new bytes.
    exec "${RELEASES_DIR}/${kit}/install.sh" --yes
}

rollback() {
    need_root
    [ -f .last-rollback ] || fail "ไม่มีข้อมูลการอัปเดตครั้งก่อน (.last-rollback)"
    local stamp from to img a
    stamp="$(sed -n 's/^stamp=//p' .last-rollback)"
    from="$(sed -n 's/^from_version=//p' .last-rollback)"
    to="$(sed -n 's/^to_version=//p' .last-rollback)"
    for img in backend frontend ai-service; do
        docker image inspect "smart-campus/${img}:rollback-${stamp}" >/dev/null 2>&1 \
            || fail "ไม่พบ image smart-campus/${img}:rollback-${stamp}"
    done
    printf 'ย้อนกลับจาก %s เป็น %s (image ที่เก็บไว้ rollback-%s)\n' "${to}" "${from}" "${stamp}"
    printf '\033[33mหมายเหตุ:\033[0m ฐานข้อมูลไม่ถูกย้อนกลับ ถ้ารุ่นใหม่เปลี่ยนโครงสร้างฐานข้อมูลจนรุ่นเดิมใช้ไม่ได้\n'
    printf 'ให้กู้จากไฟล์สำรองที่ทำก่อนอัปเดต ตามเอกสาร UPGRADE_AND_ROLLBACK\n'
    read -r -p "ดำเนินการต่อ? [y/N]: " a
    case "${a}" in y|Y|yes|YES) ;; *) printf 'ยกเลิก\n'; return 0 ;; esac
    for img in backend frontend ai-service; do
        docker tag "smart-campus/${img}:rollback-${stamp}" "smart-campus/${img}:pilot"
    done
    "${DC[@]}" up -d --wait --wait-timeout 900 --force-recreate backend queue scheduler websocket frontend ai-service
    "${DC[@]}" exec -T backend php artisan up || true
    printf '%s\n' "${from}" > VERSION
    printf '%s rollback %s -> %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "${to}" "${from}" >> update-history.log
    publish_update_state
    printf '\033[32mย้อนกลับเป็น %s แล้ว\033[0m ตรวจระบบ: sudo smart-campus preflight\n' "${from}"
}

web_update() { # web-update on|off|status: the System update page's "update now" button
    case "${1:-status}" in
        on)
            need_root
            rm -f "${INSTALL_DIR}/.web-update-off"
            bash "${INSTALL_DIR}/update-agent-setup.sh" "${INSTALL_DIR}"
            printf 'เปิดการอัปเดตผ่านเว็บแล้ว (ผู้ใช้ที่มีสิทธิ์ system.update เท่านั้น)\n' ;;
        off)
            need_root
            touch "${INSTALL_DIR}/.web-update-off"
            systemctl disable --now smart-campus-update-agent.path >/dev/null 2>&1 || true
            printf '{"enabled": false, "reason": "DISABLED_BY_ADMIN"}\n' > "${INSTALL_DIR}/update-state/agent.json"
            printf 'ปิดการอัปเดตผ่านเว็บแล้ว หน้าเว็บยังแสดงรุ่นและคำสั่งให้รันบนเซิร์ฟเวอร์\n' ;;
        status)
            systemctl is-enabled smart-campus-update-agent.path 2>/dev/null || true
            cat "${INSTALL_DIR}/update-state/status.json" 2>/dev/null || printf 'ยังไม่มีงานจากหน้าเว็บ\n' ;;
        *) fail "ใช้: smart-campus web-update on|off|status" ;;
    esac
}

case "${1:-help}" in
    status)    "${DC[@]}" ps ;;
    start)     "${DC[@]}" up -d --wait --wait-timeout 900 ;;
    stop)      "${DC[@]}" stop ;;
    restart)   "${DC[@]}" restart "${@:2}" ;;
    logs)      "${DC[@]}" logs --tail=200 -f "${@:2}" ;;
    backup)    "${DC[@]}" exec -T backend php artisan backup:run --type=ALL --verify ;;
    preflight) "${DC[@]}" exec -T backend php artisan pilot:preflight ;;
    artisan)   "${DC[@]}" exec backend php artisan "${@:2}" ;;
    tls)       COMPOSE="${DC[*]}" "${INSTALL_DIR}/scripts/tls-setup.sh" "${@:2}" ;;
    version)   cat VERSION; [ -f update-history.log ] && tail -n 5 update-history.log || true ;;
    update-setup) update_setup ;;
    update-check) update_check ;;
    update)    update_run "${@:2}" ;;
    rollback)  rollback ;;
    web-update) web_update "${@:2}" ;;
    *)
        cat <<'USAGE'
smart-campus <command>
  status              สถานะของแต่ละบริการ
  start | stop        เริ่ม / หยุดระบบ
  restart [service]   เริ่มใหม่ (ทั้งหมด หรือเฉพาะบริการ)
  logs [service]      ดู log
  backup              สำรองข้อมูลทันที (ตรวจสอบไฟล์สำรองด้วย)
  preflight           ตรวจความพร้อมใช้งานจริง
  artisan <args>      คำสั่งดูแลระบบของ backend เช่น rms:sync --dry-run
  tls <args>          จัดการใบรับรอง HTTPS (status | import | internal-ca | letsencrypt)
  version             รุ่นที่ติดตั้งและประวัติการอัปเดต

อัปเดตผ่าน GitHub (ผู้ดูแลกดเอง):
  update-setup        ตั้งค่าครั้งแรก: repository และ token แบบอ่านอย่างเดียว
  update-check        ดูรุ่นที่มีบน GitHub เทียบกับรุ่นที่ติดตั้ง
  update [รุ่น]        ดาวน์โหลด ตรวจ SHA-256 สำรองข้อมูล แล้วอัปเดต (ไม่ระบุ = รุ่นล่าสุด)
  rollback            ย้อนกลับไปรุ่นก่อนการอัปเดตครั้งล่าสุด
  web-update on|off   เปิด/ปิดปุ่มอัปเดตในหน้าเว็บ "อัปเดตระบบ" (status = ดูงานล่าสุด)
อัปเดตแบบไม่มีอินเทอร์เน็ต: แตกชุดติดตั้งรุ่นใหม่ แล้วรัน sudo ./install.sh ในโฟลเดอร์นั้น
USAGE
        ;;
esac
