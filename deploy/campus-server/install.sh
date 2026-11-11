#!/usr/bin/env bash
# =============================================================================
# AI Smart Campus — campus server installer (offline kit)
#
#   sudo ./install.sh            install, or update an existing installation
#   sudo ./install.sh --yes      accept every default (no questions)
#
# Fresh install: asks 4 questions (all have defaults), generates every secret,
# loads the images shipped in the kit, creates the database, object storage,
# HTTPS certificate (internal CA) and the first administrator, then checks
# health. Update (campus.env already present): verified backup first, then
# the new images, migrations and restart. Existing data are never deleted.
#
# Answers can also come from the environment:
#   SC_SCHOOL_NAME  SC_HOST  SC_ADMIN_USER  SC_BACKUP_DIR  SC_INSTALL_DIR
#   SC_AI_MODE (default onnx: YuNet + SFace + MiniFASNet, ACR-AI-01;
#   mock gives synthetic results for demos only)
# =============================================================================
set -euo pipefail

KIT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INSTALL_DIR="${SC_INSTALL_DIR:-/opt/smart-campus}"
ENV_FILE="${INSTALL_DIR}/campus.env"
LOG="${INSTALL_DIR}/install.log"
ASSUME_YES=0
SKIP_BACKUP=0
for arg in "$@"; do
    case "${arg}" in
        --yes|-y) ASSUME_YES=1 ;;
        --skip-backup) SKIP_BACKUP=1 ;;
        -h|--help) sed -n 2,19p "$0"; exit 0 ;;
        *) echo "unknown option: ${arg}" >&2; exit 2 ;;
    esac
done

# ---- output helpers ---------------------------------------------------------
say()  { printf '%s\n' "$*"; }
ok()   { printf '  \033[32m✓\033[0m %s\n' "$*"; }
warn() { printf '  \033[33m!\033[0m %s\n' "$*"; }
UPDATE_IN_PROGRESS=0
die()  {
    printf '\n\033[31mติดตั้งไม่สำเร็จ:\033[0m %s\n' "$*" >&2
    [ -f "${LOG}" ] && printf 'รายละเอียด: %s (20 บรรทัดสุดท้าย)\n' "${LOG}" >&2 && tail -n 20 "${LOG}" >&2
    if [ "${UPDATE_IN_PROGRESS}" -eq 1 ]; then
        printf '\nระบบยังอยู่ในโหมดปรับปรุง (ผู้ใช้เข้าเว็บไม่ได้) ข้อมูลถูกสำรองไว้ก่อนอัปเดตแล้ว\n' >&2
        printf '  ย้อนกลับไปรุ่นเดิม:  sudo smart-campus rollback\n' >&2
        printf '  หรือแก้สาเหตุแล้วรันตัวติดตั้งนี้ซ้ำ\n' >&2
    fi
    exit 1
}

# step "<description>" cmd...: runs quietly, output to the log, ✓ or stop.
step() {
    local desc="$1"; shift
    printf '  … %s' "${desc}"
    if { printf '\n== %s\n' "${desc}"; "$@"; } >>"${LOG}" 2>&1; then
        printf '\r'; ok "${desc}"
    else
        printf '\n'; die "${desc}"
    fi
}

ask() { # ask VAR "question" default
    local var="$1" question="$2" default="$3" answer
    if [ -n "${!var:-}" ]; then return; fi
    if [ "${ASSUME_YES}" -eq 1 ] || [ ! -t 0 ]; then
        printf -v "${var}" '%s' "${default}"; return
    fi
    read -r -p "  ${question} [${default}]: " answer
    answer="$(printf '%s' "${answer:-${default}}" | tr -d '"\\`$')"
    printf -v "${var}" '%s' "${answer}"
}

confirm() { # confirm "question" -> 0 yes
    [ "${ASSUME_YES}" -eq 1 ] && return 0
    local a; read -r -p "  $1 [Y/n]: " a
    # Letters only: a CR or escape bytes from a Windows SSH client must not
    # turn an "n" into the default yes.
    a="$(printf '%s' "${a}" | LC_ALL=C tr -cd 'A-Za-z' | LC_ALL=C tr 'A-Z' 'a-z')"
    case "${a}" in n|no) return 1 ;; *) return 0 ;; esac
}

dc() {
    (cd "${INSTALL_DIR}" && BACKEND_ENV_FILE=./campus.env docker compose --env-file ./campus.env \
        -f docker-compose.yml -f docker-compose.pilot.yml -f docker-compose.kit.yml "$@")
}

secret() { openssl rand -hex 24; }

set_env() { # set_env KEY VALUE: replace or append one line of campus.env
    local tmp; tmp="$(mktemp)"
    awk -v k="$1" -v v="$2" 'BEGIN { FS = "=" }
        $1 == k { print k "=" v; done = 1; next } { print }
        END { if (!done) print k "=" v }' "${ENV_FILE}" > "${tmp}"
    cat "${tmp}" > "${ENV_FILE}"; rm -f "${tmp}"
}

env_value() { grep -E "^$1=" "${ENV_FILE}" | tail -n 1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

# ---- 0. checks --------------------------------------------------------------
say "============================================================"
say " AI Smart Campus — ติดตั้งเซิร์ฟเวอร์หลัก ($(cat "${KIT_DIR}/VERSION" 2>/dev/null || echo dev))"
say "============================================================"

[ "$(id -u)" -eq 0 ] || { echo "ต้องรันด้วยสิทธิ์ root: sudo ./install.sh" >&2; exit 1; }
[ "$(uname -s)" = "Linux" ] || { echo "ชุดติดตั้งนี้ใช้กับ Linux เท่านั้น" >&2; exit 1; }
case "$(uname -m)" in x86_64|amd64) HOST_ARCH=amd64 ;; aarch64|arm64) HOST_ARCH=arm64 ;; *) HOST_ARCH="$(uname -m)" ;; esac
KIT_ARCH="$(cat "${KIT_DIR}/ARCH" 2>/dev/null || echo amd64)"
[ "${HOST_ARCH}" = "${KIT_ARCH}" ] || { echo "ชุดติดตั้งนี้สำหรับ ${KIT_ARCH} แต่เครื่องนี้เป็น ${HOST_ARCH}" >&2; exit 1; }
for tool in openssl awk sha256sum gzip; do
    command -v "${tool}" >/dev/null 2>&1 || { echo "ไม่พบโปรแกรม ${tool}" >&2; exit 1; }
done

mkdir -p "${INSTALL_DIR}"
: > "${LOG}"
chmod 600 "${LOG}"

say ""
say "1) ตรวจเครื่อง"
if ! command -v docker >/dev/null 2>&1 || ! docker compose version >/dev/null 2>&1; then
    warn "ยังไม่มี Docker / Docker Compose"
    if command -v apt-get >/dev/null 2>&1 && confirm "ติดตั้ง Docker จากคลังแพ็กเกจของระบบ (apt) เลยไหม"; then
        step "ติดตั้ง Docker (apt)" sh -c 'apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y docker.io docker-compose-v2 && systemctl enable --now docker'
    else
        die "ติดตั้ง Docker Engine และ Compose plugin ก่อน: https://docs.docker.com/engine/install/"
    fi
fi
docker info >/dev/null 2>&1 || die "Docker ติดตั้งแล้วแต่ยังไม่ทำงาน (systemctl start docker)"
ok "Docker $(docker version --format '{{.Server.Version}}' 2>/dev/null)"

MEM_GB=$(awk '/MemTotal/ { printf "%d", $2 / 1024 / 1024 }' /proc/meminfo)
[ "${MEM_GB}" -ge 7 ] && ok "หน่วยความจำ ${MEM_GB} GB" || warn "หน่วยความจำ ${MEM_GB} GB (แนะนำอย่างน้อย 8 GB)"
DISK_GB=$(df -Pk "${INSTALL_DIR}" | awk 'NR == 2 { printf "%d", $4 / 1024 / 1024 }')
[ "${DISK_GB}" -ge 40 ] && ok "พื้นที่ว่าง ${DISK_GB} GB" || warn "พื้นที่ว่าง ${DISK_GB} GB (แนะนำอย่างน้อย 40 GB)"

[ -f "${KIT_DIR}/images.tar.gz" ] || die "ไม่พบ images.tar.gz: รันจากโฟลเดอร์ชุดติดตั้งที่แตกไฟล์แล้ว ไม่ใช่จาก ${INSTALL_DIR}"
if [ -f "${KIT_DIR}/SHA256SUMS" ]; then
    step "ตรวจความครบถ้วนของชุดติดตั้ง" sh -c "cd '${KIT_DIR}' && sha256sum -c --quiet SHA256SUMS"
fi

MODE=install
[ -f "${ENV_FILE}" ] && MODE=update
if [ "${MODE}" = install ] && docker volume inspect smart-campus_postgres-data >/dev/null 2>&1; then
    die "พบฐานข้อมูลเดิม (volume smart-campus_postgres-data) แต่ไม่พบ ${ENV_FILE}
  ตัวติดตั้งจะไม่ลบหรือเขียนทับข้อมูลเดิม: นำไฟล์ campus.env เดิมกลับมาไว้ที่ ${ENV_FILE} แล้วรันใหม่"
fi
if [ "${MODE}" = install ] && command -v ss >/dev/null 2>&1; then
    for port in 80 443; do
        if ss -ltnH "( sport = :${port} )" | grep -q .; then
            die "พอร์ต ${port} ถูกโปรแกรมอื่นใช้อยู่ (ปิดโปรแกรมนั้น เช่น apache2/nginx ของระบบ ก่อน)"
        fi
    done
fi

# ---- 1. questions and campus.env (fresh install only) ---------------------
if [ "${MODE}" = install ]; then
    say ""
    say "2) ข้อมูลสถานศึกษา (กด Enter เพื่อใช้ค่าในวงเล็บ)"
    DEFAULT_HOST="$( (hostname -I 2>/dev/null || ip -4 route get 1 2>/dev/null | awk '{ for (i = 1; i < NF; i++) if ($i == "src") print $(i + 1) }') | awk 'NR == 1 { print $1 }')" || DEFAULT_HOST=""
    ask SC_SCHOOL_NAME "ชื่อสถานศึกษา" "Smart Campus"
    ask SC_HOST "ชื่อเครื่องหรือ IP ที่ผู้ใช้จะเปิดเว็บ" "${DEFAULT_HOST:-localhost}"
    ask SC_ADMIN_USER "ชื่อผู้ใช้ของผู้ดูแลระบบคนแรก" "admin"
    ask SC_BACKUP_DIR "โฟลเดอร์เก็บสำรองข้อมูล (ควรเป็นดิสก์อื่นหรือเครื่องอื่น)" "/var/backups/smart-campus"
    SC_AI_MODE="${SC_AI_MODE:-onnx}"
fi

say ""
say "3) $([ "${MODE}" = install ] && echo 'ติดตั้ง' || echo 'อัปเดต')"

if [ "${MODE}" = update ] && dc ps --status running --services 2>/dev/null | grep -qx backend; then
    if [ "${SKIP_BACKUP}" -eq 0 ]; then
        step "สำรองข้อมูลก่อนอัปเดต (ตรวจสอบแล้ว)" dc exec -T backend php artisan backup:run --type=ALL --verify
    else
        warn "ข้ามการสำรองข้อมูลก่อนอัปเดต (--skip-backup)"
    fi
    STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
    for img in backend frontend ai-service; do
        docker image inspect "smart-campus/${img}:pilot" >/dev/null 2>&1 \
            && docker tag "smart-campus/${img}:pilot" "smart-campus/${img}:rollback-${STAMP}"
    done
    # Read by `smart-campus rollback`: which images and version to return to.
    printf 'stamp=%s\nfrom_version=%s\nto_version=%s\nstarted_at=%s\n' "${STAMP}" \
        "$(cat "${INSTALL_DIR}/VERSION" 2>/dev/null || echo unknown)" "$(cat "${KIT_DIR}/VERSION")" \
        "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "${INSTALL_DIR}/.last-rollback"
    ok "เก็บ image เดิมไว้สำหรับย้อนกลับ (rollback-${STAMP})"
    step "เข้าสู่โหมดปรับปรุงระบบ" dc exec -T backend php artisan down --retry=60
    UPDATE_IN_PROGRESS=1
fi

copy_runtime() {
    cp "${KIT_DIR}/docker-compose.yml" "${KIT_DIR}/docker-compose.pilot.yml" "${KIT_DIR}/docker-compose.kit.yml" \
        "${KIT_DIR}/campus.sh" "${KIT_DIR}/install.sh" "${KIT_DIR}/README.md" "${KIT_DIR}/VERSION" "${INSTALL_DIR}/"
    if [ -f "${KIT_DIR}/ARCH" ]; then cp "${KIT_DIR}/ARCH" "${INSTALL_DIR}/"; fi
    mkdir -p "${INSTALL_DIR}/infrastructure/pilot/certs" "${INSTALL_DIR}/scripts" "${INSTALL_DIR}/docs/security"
    cp -r "${KIT_DIR}/infrastructure/objectstore" "${KIT_DIR}/infrastructure/postgres" "${INSTALL_DIR}/infrastructure/"
    rm -rf "${INSTALL_DIR}/infrastructure/pilot/nginx"
    cp -r "${KIT_DIR}/infrastructure/pilot/nginx" "${INSTALL_DIR}/infrastructure/pilot/"
    cp "${KIT_DIR}/infrastructure/pilot/pilot.env.example" "${INSTALL_DIR}/infrastructure/pilot/"
    cp "${KIT_DIR}/scripts/tls-setup.sh" "${INSTALL_DIR}/scripts/"
    cp "${KIT_DIR}/docs/security/LEAST-PRIVILEGE-ROLES.sql" "${INSTALL_DIR}/docs/security/"
    chmod +x "${INSTALL_DIR}"/*.sh "${INSTALL_DIR}/scripts/tls-setup.sh"
    ln -sf "${INSTALL_DIR}/campus.sh" /usr/local/bin/smart-campus
}
if [ "${KIT_DIR}" != "${INSTALL_DIR}" ]; then
    step "คัดลอกไฟล์ระบบไปที่ ${INSTALL_DIR}" copy_runtime
fi

write_env() {
    tr -d '\r' < "${INSTALL_DIR}/infrastructure/pilot/pilot.env.example" > "${ENV_FILE}"
    chmod 600 "${ENV_FILE}"
    set_env APP_NAME "\"${SC_SCHOOL_NAME}\""
    set_env APP_KEY "base64:$(openssl rand -base64 32)"
    set_env APP_URL "https://${SC_HOST}"
    set_env CORS_ALLOWED_ORIGINS "https://${SC_HOST}"
    set_env POSTGRES_PASSWORD "$(secret)"
    set_env DB_PASSWORD "$(secret)"
    set_env MIGRATION_DB_PASSWORD "$(secret)"
    set_env MONITORING_DB_PASSWORD "$(secret)"
    set_env BACKUP_DB_PASSWORD "$(secret)"
    set_env REDIS_PASSWORD "$(secret)"
    set_env REVERB_APP_ID "$(( 100000 + $(od -An -N3 -tu4 /dev/urandom | tr -d ' ') % 900000 ))"
    # Public key baked into the kit's frontend image; the secret is per install.
    set_env REVERB_APP_KEY "$(cat "${KIT_DIR}/REVERB_APP_KEY")"
    set_env REVERB_APP_SECRET "$(secret)"
    set_env OBJECTSTORE_ROOT_USER "campus-root"
    set_env OBJECTSTORE_ROOT_PASSWORD "$(secret)"
    set_env AWS_ACCESS_KEY_ID "campus-app"
    set_env AWS_SECRET_ACCESS_KEY "$(secret)"
    set_env BACKUP_ENCRYPTION_KEY "$(openssl rand -base64 32)"
    set_env BACKUP_OFFHOST_HOST_PATH "${SC_BACKUP_DIR}"
    set_env AI_MODE "${SC_AI_MODE}"
    printf 'school=%s\nhost=%s\nadmin=%s\nbackup_dir=%s\ninstalled_at=%s\nkit=%s\n' \
        "${SC_SCHOOL_NAME}" "${SC_HOST}" "${SC_ADMIN_USER}" "${SC_BACKUP_DIR}" \
        "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$(cat "${KIT_DIR}/VERSION")" > "${INSTALL_DIR}/install-info.txt"
}

merge_new_env_keys() { # keys added by a newer release, existing values untouched
    local key
    while IFS= read -r line; do
        line="${line%$'\r'}"
        key="${line%%=*}"
        case "${key}" in ''|'#'*) continue ;; esac
        grep -qE "^${key}=" "${ENV_FILE}" || printf '%s\n' "${line}" >> "${ENV_FILE}"
    done < "${INSTALL_DIR}/infrastructure/pilot/pilot.env.example"
}

# ---- one-off move of files from MinIO (installs before 2026-11) -------------
LEGACY_MINIO_VOLUME="smart-campus_minio-data"
LEGACY_MINIO_CONTAINER="smart-campus-minio-1"
NEEDS_STORAGE_MIGRATION=0

legacy_minio_up() { # the old MinIO, reachable as "minio" on the app network
    local envf
    if docker container inspect "${LEGACY_MINIO_CONTAINER}" >/dev/null 2>&1; then
        docker start "${LEGACY_MINIO_CONTAINER}" >/dev/null
    else
        docker image inspect smart-campus/vendor-minio:kit >/dev/null 2>&1 \
            || { echo "legacy MinIO container and image smart-campus/vendor-minio:kit not found"; return 1; }
        LEGACY_MINIO_CONTAINER="smart-campus-minio-legacy"
        docker rm -f "${LEGACY_MINIO_CONTAINER}" >/dev/null 2>&1 || true
        envf="$(mktemp)"; chmod 600 "${envf}"
        printf 'MINIO_ROOT_USER=%s\nMINIO_ROOT_PASSWORD=%s\n' \
            "$(env_value MINIO_ROOT_USER)" "$(env_value MINIO_ROOT_PASSWORD)" > "${envf}"
        docker run -d --name "${LEGACY_MINIO_CONTAINER}" --network smart-campus_smartcampus \
            --network-alias minio --env-file "${envf}" -v "${LEGACY_MINIO_VOLUME}:/data" \
            smart-campus/vendor-minio:kit server /data >/dev/null || { rm -f "${envf}"; return 1; }
        rm -f "${envf}"
    fi
    for _ in $(seq 1 60); do
        docker exec "${LEGACY_MINIO_CONTAINER}" curl -fsS http://localhost:9000/minio/health/live >/dev/null 2>&1 && return 0
        sleep 2
    done
    echo "legacy MinIO did not become ready"; return 1
}

migrate_legacy_storage() {
    step "เปิดที่เก็บไฟล์ MinIO เดิมเพื่อย้ายข้อมูล" legacy_minio_up
    step "ย้ายไฟล์จาก MinIO ไปที่เก็บไฟล์ใหม่ (ตรวจทุกไบต์)" dc --profile migrate run --rm storage-migrate
    # Only now does the app switch over; until here a rollback still finds
    # every file in the old MinIO.
    set_env AWS_ENDPOINT "http://objectstore:8333"
    date -u +%Y-%m-%dT%H:%M:%SZ > "${INSTALL_DIR}/.objectstore-migrated"
    docker rm -f "${LEGACY_MINIO_CONTAINER}" >/dev/null 2>&1 || true
    ok "ย้ายไฟล์เสร็จ (volume ${LEGACY_MINIO_VOLUME} เดิมยังเก็บไว้ ลบเองได้เมื่อมั่นใจ)"
}

if [ "${MODE}" = install ]; then
    step "สร้างไฟล์ตั้งค่าและรหัสผ่านทั้งหมดแบบสุ่ม (campus.env)" write_env
else
    step "เพิ่มค่าตั้งใหม่ของรุ่นนี้ใน campus.env (ค่าเดิมไม่เปลี่ยน)" merge_new_env_keys
    # Every kit build bakes a new public Reverb key into the frontend image.
    # Keeping the old key in campus.env broke realtime (Live Monitor) after
    # every update. The per-install REVERB_APP_SECRET is left unchanged.
    if [ -s "${KIT_DIR}/REVERB_APP_KEY" ]; then
        set_env REVERB_APP_KEY "$(cat "${KIT_DIR}/REVERB_APP_KEY")"
        ok "ปรับ REVERB_APP_KEY ให้ตรงกับหน้าเว็บของรุ่นนี้"
    fi
    # Object storage moved from MinIO to SeaweedFS (MinIO images are no
    # longer published). The app keys (AWS_*) are reused unchanged.
    [ -n "$(env_value OBJECTSTORE_ROOT_USER)" ] || set_env OBJECTSTORE_ROOT_USER "campus-root"
    [ -n "$(env_value OBJECTSTORE_ROOT_PASSWORD)" ] || set_env OBJECTSTORE_ROOT_PASSWORD "$(secret)"
    if docker volume inspect "${LEGACY_MINIO_VOLUME}" >/dev/null 2>&1 \
        && [ ! -f "${INSTALL_DIR}/.objectstore-migrated" ]; then
        NEEDS_STORAGE_MIGRATION=1
        [ -n "$(env_value MINIO_ROOT_USER)" ] && [ -n "$(env_value MINIO_ROOT_PASSWORD)" ] \
            || die "พบข้อมูลไฟล์ของ MinIO เดิม แต่ไม่พบ MINIO_ROOT_USER/MINIO_ROOT_PASSWORD ใน ${ENV_FILE}"
    elif [ "$(env_value AWS_ENDPOINT)" = "http://minio:9000" ]; then
        set_env AWS_ENDPOINT "http://objectstore:8333"
    fi
fi

# Web "System update" page (ACR-OPS-01): state/request dirs, UPDATE_AGENT_KEY,
# systemd units. Before the backend starts, which mounts those dirs.
step "ตั้งค่าการอัปเดตผ่านหน้าเว็บ" bash "${KIT_DIR}/update-agent-setup.sh" "${INSTALL_DIR}"

BACKUP_DIR="$(env_value BACKUP_OFFHOST_HOST_PATH)"
mkdir -p "${BACKUP_DIR}"
chmod 700 "${BACKUP_DIR}"
if [ "$(df -P "${BACKUP_DIR}" | awk 'NR == 2 { print $1 }')" = "$(df -P "${INSTALL_DIR}" | awk 'NR == 2 { print $1 }')" ]; then
    BACKUP_SAME_DISK=1
else
    BACKUP_SAME_DISK=0
fi

step "โหลด image ระบบจากชุดติดตั้ง (ไม่ต้องใช้อินเทอร์เน็ต)" sh -c "gunzip -c '${KIT_DIR}/images.tar.gz' | docker load"

HOST="$(env_value APP_URL | sed -e 's#^https://##' -e 's#/.*##')"
if [ ! -s "${INSTALL_DIR}/infrastructure/pilot/certs/fullchain.pem" ]; then
    step "สร้างใบรับรอง HTTPS ภายใน (internal CA) สำหรับ ${HOST}" \
        env COMPOSE="true" "${INSTALL_DIR}/scripts/tls-setup.sh" internal-ca "${HOST}"
fi

step "ตรวจไฟล์ตั้งค่า" dc config --quiet
step "เริ่มฐานข้อมูล, Redis และที่เก็บไฟล์" dc up -d --wait --wait-timeout 300 postgres redis objectstore
step "สร้าง bucket ของที่เก็บไฟล์" dc run --rm objectstore-init
if [ "${NEEDS_STORAGE_MIGRATION}" -eq 1 ]; then
    migrate_legacy_storage
fi
step "สร้างบทบาทฐานข้อมูลแบบสิทธิ์ต่ำสุด" dc --profile setup run --rm db-roles
step "เริ่ม backend" dc up -d --wait --wait-timeout 300 backend

MIG_PASS="$(env_value MIGRATION_DB_PASSWORD)"
M=(-e DB_USERNAME=campus_migration_user -e "DB_PASSWORD=${MIG_PASS}")
step "สร้าง/ปรับโครงสร้างฐานข้อมูล" dc exec -T "${M[@]}" backend php artisan migrate --force
step "ใส่ข้อมูลตั้งต้นและสิทธิ์" dc exec -T "${M[@]}" backend php artisan db:seed --force
step "ใส่ค่าตั้งระบบเริ่มต้น" dc exec -T "${M[@]}" backend php artisan db:seed --class=SystemSettingSeeder --force
if [ "$(env_value AI_MODE)" = onnx ]; then
    # Refuses (without --force) when faces enrolled with another model would stop matching.
    if ! dc exec -T backend php artisan face:model-activate yunet-sface 2021dec >>"${LOG}" 2>&1; then
        warn "ยังไม่ได้เปิดใช้โมเดลจดจำใบหน้า: มีใบหน้าที่ลงทะเบียนด้วยโมเดลอื่น ดูวิธีแก้ใน ${LOG}"
    else
        ok "เปิดใช้โมเดลจดจำใบหน้า YuNet + SFace"
    fi
fi

ADMIN_OUTPUT=""
if [ "${MODE}" = install ]; then
    SCHOOL="$(env_value APP_NAME)"
    step "สร้างสถานที่หลักและประตูหลัก" dc exec -T backend php artisan locations:provision MAIN "${SCHOOL}" --zone="GATE-1:ประตูหลัก:GATE"
    # Not logged: the output carries the one-time password.
    if ADMIN_OUTPUT="$(dc exec -T backend php artisan access:bootstrap-admin "${SC_ADMIN_USER}" 2>&1)"; then
        ok "สร้างผู้ดูแลระบบ ${SC_ADMIN_USER}"
    else
        warn "ไม่ได้สร้างผู้ดูแลระบบใหม่ (มีผู้ดูแลอยู่แล้ว)"
        ADMIN_OUTPUT=""
    fi
fi

step "เตรียม cache ของระบบ" dc exec -T backend sh -c 'php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache'
step "เริ่มทุกบริการและรอจนพร้อม (ครั้งแรกอาจใช้หลายนาที)" dc up -d --wait --wait-timeout 900
if [ "${MODE}" = update ]; then
    step "ออกจากโหมดปรับปรุงระบบ" dc exec -T backend php artisan up
    UPDATE_IN_PROGRESS=0
    printf '%s update %s -> %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
        "$(sed -n 's/^from_version=//p' "${INSTALL_DIR}/.last-rollback" 2>/dev/null)" \
        "$(cat "${KIT_DIR}/VERSION")" >> "${INSTALL_DIR}/update-history.log"
fi
python3 -I "${INSTALL_DIR}/update-agent.py" publish-state --install-dir "${INSTALL_DIR}" >>"${LOG}" 2>&1 || true

say ""
say "4) ตรวจระบบ"
if dc exec -T backend php artisan backup:run --type=ALL --verify >>"${LOG}" 2>&1; then
    ok "สำรองข้อมูลครั้งแรกและตรวจสอบแล้ว (${BACKUP_DIR})"
else
    warn "สำรองข้อมูลไม่สำเร็จ ดู ${LOG}"
fi
if curl -fsSk "https://127.0.0.1/health/full" -o /dev/null 2>>"${LOG}"; then
    ok "เว็บตอบกลับทาง HTTPS"
else
    warn "เว็บยังไม่ตอบทาง HTTPS ดู: smart-campus status"
fi
PREFLIGHT="$(dc exec -T backend php artisan pilot:preflight 2>&1 || true)"
printf '%s\n' "${PREFLIGHT}" >> "${LOG}"
FAILS="$(printf '%s\n' "${PREFLIGHT}" | sed -n 's/.*Preflight [A-Z]*: \([0-9][0-9]*\) failure.*/\1/p' | tail -n 1)"
FAILS="${FAILS:-1}"
if [ "${FAILS}" -eq 0 ]; then
    ok "ตรวจความพร้อมใช้งานจริง (pilot:preflight) ผ่าน"
else
    warn "ตรวจความพร้อมใช้งานจริงพบ ${FAILS} รายการที่ต้องจัดการ (smart-campus preflight)"
fi

say ""
say "============================================================"
say " $([ "${MODE}" = install ] && echo 'ติดตั้งเสร็จแล้ว' || echo 'อัปเดตเสร็จแล้ว')"
say "============================================================"
say " เปิดเว็บ:        https://${HOST}"
if [ -n "${ADMIN_OUTPUT}" ]; then
    say " ผู้ดูแลระบบ:     ${SC_ADMIN_USER}"
    say " รหัสผ่านครั้งแรก (แสดงครั้งเดียว ระบบจะให้เปลี่ยนตอนเข้าครั้งแรก):"
    printf '%s\n' "${ADMIN_OUTPUT}" | awk 'found { print "   " $0; exit } /One-time password/ { found = 1 }'
fi
say ""
say " สิ่งที่ต้องทำต่อ:"
say "  1. คัดลอก ${ENV_FILE} ไปเก็บไว้นอกเครื่องนี้ (มีรหัสผ่านทั้งหมดและกุญแจถอดรหัสไฟล์สำรอง)"
say "  2. ให้เครื่องผู้ใช้เชื่อถือใบรับรอง: ติดตั้งไฟล์ ${INSTALL_DIR}/infrastructure/pilot/ca/ca.crt"
say "     (หรือใช้ใบรับรองของสถานศึกษา: ${INSTALL_DIR}/scripts/tls-setup.sh import <fullchain> <privkey>)"
say "  3. เข้าเว็บ แล้วอัปโหลดไฟล์ license ที่ได้รับจากผู้ขาย"
if [ "$(env_value AI_MODE)" = onnx ]; then
    say "  4. AI_MODE=onnx: ค่าเกณฑ์การจดจำใบหน้ายังต้องทดสอบกับอาสาสมัครที่ยินยอมก่อนใช้จริง (FACE-021)"
else
    say "  4. AI_MODE=$(env_value AI_MODE): ผลจดจำใบหน้าเป็นข้อมูลจำลอง ไม่ใช่การจดจำจริง"
fi
[ "${BACKUP_SAME_DISK}" -eq 1 ] && say "  5. โฟลเดอร์สำรองข้อมูลอยู่ดิสก์เดียวกับระบบ: ควรย้ายไปดิสก์อื่นหรือเครื่องอื่น"
say ""
say " คำสั่งดูแลระบบ: smart-campus status | logs | restart | backup | preflight"
say " บันทึกการติดตั้ง: ${LOG}"
