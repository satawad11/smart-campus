#!/usr/bin/env bash
# AI Smart Campus — day-to-day commands for a server installed from the kit.
# Installed as /usr/local/bin/smart-campus by install.sh.
set -euo pipefail

INSTALL_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")" && pwd)"
cd "${INSTALL_DIR}"
export BACKEND_ENV_FILE=./campus.env
DC=(docker compose --env-file ./campus.env -f docker-compose.yml -f docker-compose.pilot.yml -f docker-compose.kit.yml)

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
    version)   cat VERSION ;;
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
  version             รุ่นที่ติดตั้ง
อัปเดต: แตกชุดติดตั้งรุ่นใหม่ แล้วรัน sudo ./install.sh ในโฟลเดอร์นั้น
USAGE
        ;;
esac
