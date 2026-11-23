/**
 * Thai display labels for codes the API returns. Codes stay unchanged in
 * the API contract; only what the user sees is translated.
 */

export const DECISION_LABELS = {
  ALLOW: 'อนุญาต',
  DENY: 'ปฏิเสธ',
  REVIEW: 'รอตรวจสอบ',
  UNKNOWN: 'ไม่ทราบตัวตน',
}

/** System role codes (RoleSeeder); custom roles fall back to their own name. */
export const ROLE_LABELS = {
  SUPER_ADMIN: 'ผู้ดูแลระบบสูงสุด',
  SYSTEM_ADMIN: 'ผู้ดูแลระบบ',
  DISCIPLINE_OFFICER: 'เจ้าหน้าที่งานปกครอง',
  SECURITY_OFFICER: 'เจ้าหน้าที่รักษาความปลอดภัย',
  EXECUTIVE_VIEWER: 'ผู้บริหาร',
}

/** Thai messages for API error codes shown on general pages. */
export const ERROR_MESSAGES = {
  UNAUTHENTICATED: 'เซสชันหมดอายุ กรุณาเข้าสู่ระบบใหม่',
  PERMISSION_DENIED: 'คุณไม่มีสิทธิ์ดูข้อมูลส่วนนี้',
  PASSWORD_CHANGE_REQUIRED: 'กรุณาเปลี่ยนรหัสผ่านก่อนใช้งาน',
  MFA_ENROLLMENT_REQUIRED: 'กรุณาตั้งค่าการยืนยันตัวตนสองชั้นก่อนใช้งาน',
  SITE_REQUIRED: 'บัญชีของคุณดูแลหลายพื้นที่ กรุณาเลือกพื้นที่ที่ต้องการดู',
  VALIDATION_ERROR: 'ข้อมูลที่ส่งไม่ถูกต้อง',
  NO_FACE: 'ไม่พบใบหน้าในภาพ กรุณาใช้ภาพที่เห็นใบหน้าชัดเจน',
  MULTIPLE_FACES: 'พบใบหน้ามากกว่าหนึ่งคนในภาพ กรุณาใช้ภาพที่มีเพียงหนึ่งคน',
  FACE_QUALITY_REJECTED: 'คุณภาพภาพไม่ผ่านเกณฑ์ (เบลอ มืด หรือมุมไม่ตรง) กรุณาถ่ายใหม่',
  FACE_QUALITY_INVALID: 'คุณภาพภาพไม่ผ่านเกณฑ์ กรุณาถ่ายใหม่',
  FACE_ALREADY_ENROLLED: 'บุคคลนี้ลงทะเบียนใบหน้าแล้ว ใช้การลงทะเบียนใหม่แทน',
  FACE_PROFILE_NOT_FOUND: 'ไม่พบข้อมูลใบหน้า',
  FACE_PROFILE_REVOKED: 'ข้อมูลใบหน้านี้ถูกเพิกถอนแล้ว',
  UPLOAD_SCAN_UNAVAILABLE: 'ระบบตรวจไวรัสไม่พร้อมใช้งาน ไม่สามารถรับไฟล์ได้ในขณะนี้',
  AI_NODE_NOT_ACTIVE: 'ต้องเปิดใช้งานหน่วยประมวลผลก่อน',
}

/** Status/state codes shown across pages (values stay as the API sends them). */
export const STATUS_LABELS = {
  ACTIVE: 'ใช้งาน',
  INACTIVE: 'ปิดใช้งาน',
  DISABLED: 'ปิดใช้งาน',
  LOCKED: 'ถูกล็อก',
  EXPIRED: 'หมดอายุ',
  PENDING: 'รอดำเนินการ',
  REVOKED: 'ถูกเพิกถอน',
  RE_ENROLL_REQUIRED: 'ต้องลงทะเบียนใหม่',
  ONLINE: 'ออนไลน์',
  OFFLINE: 'ออฟไลน์',
  DEGRADED: 'ทำงานไม่เต็มที่',
  UNKNOWN: 'ไม่ทราบ',
  HEALTHY: 'ปกติ',
  UNHEALTHY: 'ผิดปกติ',
  WARNING: 'เฝ้าระวัง',
  CRITICAL: 'วิกฤต',
  OK: 'ปกติ',
  OPEN: 'เปิดอยู่',
  ACKNOWLEDGED: 'รับทราบแล้ว',
  RESOLVED: 'แก้ไขแล้ว',
  CLOSED: 'ปิดแล้ว',
  QUEUED: 'รอคิว',
  PROCESSING: 'กำลังประมวลผล',
  RUNNING: 'กำลังทำงาน',
  COMPLETED: 'เสร็จสิ้น',
  SUCCEEDED: 'สำเร็จ',
  SUCCESS: 'สำเร็จ',
  FAILED: 'ล้มเหลว',
  CANCELLED: 'ยกเลิกแล้ว',
  DELIVERED: 'ส่งถึงแล้ว',
  VALIDATED: 'ตรวจสอบแล้ว',
  UPLOADED: 'อัปโหลดแล้ว',
  MAINTENANCE: 'ซ่อมบำรุง',
  INFO: 'ข้อมูล',
  LOW: 'ต่ำ',
  MEDIUM: 'ปานกลาง',
  HIGH: 'สูง',
  LEAVE: 'ลาพัก',
  SUSPENDED: 'พักการใช้งาน',
  WITHDRAWN: 'พ้นสภาพ',
  GRADUATED: 'สำเร็จการศึกษา',
  EXPELLED: 'พ้นสภาพ',
  RESIGNED: 'ลาออก',
  RETIRED: 'เกษียณ',
  VISIT_ACTIVE: 'มีนัดหมาย/อยู่ระหว่างติดต่อ',
  NO_ACTIVE_VISIT: 'ไม่มีนัดหมายที่ใช้งาน',
  NO_ACTIVE_STUDENT: 'ไม่มีนักเรียนที่กำลังศึกษา',
}

export const PERSON_TYPE_LABELS = {
  STUDENT: 'นักเรียน',
  TEACHER: 'ครู',
  STAFF: 'บุคลากร',
  PARENT: 'ผู้ปกครอง',
  VISITOR: 'ผู้มาติดต่อ',
  CONTRACTOR: 'ผู้รับเหมา',
  ALUMNI: 'ศิษย์เก่า',
  OTHER: 'บุคคลอื่น',
}

export function personTypeLabel(code) {
  return PERSON_TYPE_LABELS[code] || code || '-'
}

export function statusLabel(code) {
  return STATUS_LABELS[code] || code
}

export function decisionLabel(code) {
  return DECISION_LABELS[code] || code
}

export function roleLabel(role) {
  if (role && typeof role === 'object') {
    return ROLE_LABELS[role.code] || role.name || role.code
  }
  return ROLE_LABELS[role] || role
}

/** Thai message for a normalised API error (services/api.js), never raw English. */
export function errorMessage(err, fallback) {
  return ERROR_MESSAGES[err?.code] || fallback
}

/**
 * Message for an error box on a form or action: Thai for known codes and
 * permission errors; for validation errors the first field message from the
 * server (it names the problem, e.g. a duplicate code); otherwise the Thai
 * fallback. Never the raw English envelope message.
 */
export function apiErrorText(err, fallback) {
  if (err?.code === 'VALIDATION_ERROR') {
    const first = Object.values(err.fields || {}).flat()[0]
    return first || ERROR_MESSAGES.VALIDATION_ERROR
  }
  if (ERROR_MESSAGES[err?.code]) return ERROR_MESSAGES[err.code]
  if (err?.status === 403) return ERROR_MESSAGES.PERMISSION_DENIED
  return fallback
}

/**
 * EVT-026: Thai text for recognition reasons recorded on an access event.
 * The first group comes from the AI node's quality gate (the face was
 * rejected before it was ever compared); the rest from matching/liveness.
 */
export const RECOGNITION_REASON_LABELS = {
  BAD_POSE: 'มุมใบหน้าไม่ตรง (หันข้าง ก้ม หรือเงยมากเกินไป)',
  IMAGE_BLUR: 'ภาพเบลอ',
  IMAGE_TOO_DARK: 'ภาพมืดเกินไป',
  IMAGE_TOO_BRIGHT: 'ภาพสว่างเกินไป',
  FACE_TOO_SMALL: 'ใบหน้าเล็กเกินไปในภาพ',
  LOW_RESOLUTION: 'ความละเอียดภาพต่ำเกินไป',
  LOW_QUALITY: 'คุณภาพภาพไม่ผ่านเกณฑ์',
  INVALID_IMAGE: 'ภาพไม่ถูกต้อง',
  LIVENESS_FAILED: 'ไม่ผ่านการตรวจใบหน้าจริง',
  PRESENTATION_ATTACK_SUSPECTED: 'สงสัยว่าเป็นภาพถ่ายหรือหน้าจอ',
  NO_CANDIDATES_FOUND: 'ไม่มีใบหน้าที่ลงทะเบียนให้เปรียบเทียบ',
  NO_CANDIDATE_ABOVE_THRESHOLD: 'เปรียบเทียบแล้ว ไม่มีผู้ใดคล้ายถึงเกณฑ์',
  SIMILARITY_IN_REVIEW_BAND: 'ความคล้ายอยู่ในช่วงที่ต้องให้เจ้าหน้าที่ตรวจสอบ',
  AMBIGUOUS_CANDIDATES: 'มีผู้ที่คล้ายใกล้เคียงกันมากกว่าหนึ่งคน',
  HIGH_SIMILARITY_MATCH: 'ความคล้ายถึงเกณฑ์ยืนยัน',
  SERVER_MATCH_THRESHOLD_NOT_MET: 'เซิร์ฟเวอร์ตรวจซ้ำแล้ว ความคล้ายไม่ถึงเกณฑ์ยืนยัน',
}

/** Known codes get Thai text; anything else (e.g. BACKEND_UNAVAILABLE: …) is shown as sent. */
export function recognitionReasonLabel(code) {
  if (RECOGNITION_REASON_LABELS[code]) return RECOGNITION_REASON_LABELS[code]
  const prefix = typeof code === 'string' ? code.split(':')[0] : ''
  if (prefix === 'BACKEND_UNAVAILABLE') return 'ติดต่อเซิร์ฟเวอร์ไม่ได้ขณะเปรียบเทียบ'
  return code
}

export function onOff(value) {
  return value ? 'เปิด' : 'ปิด'
}

/** Thai titles for system alert types; unknown types keep the server title. */
export const ALERT_TITLES = {
  AI_NODE_DOWN: 'หน่วยประมวลผลขาดการติดต่อ',
  AI_NODE_DISK_HIGH: 'ดิสก์ของหน่วยประมวลผลใกล้เต็ม',
  AI_NODE_MODEL_MISMATCH: 'โมเดลของหน่วยประมวลผลไม่ตรงกับทะเบียน',
  CAMERA_DOWN: 'กล้องขาดการติดต่อ',
  SERVER_DISK_LOW: 'ดิสก์ของเซิร์ฟเวอร์ใกล้เต็ม',
  QUEUE_BACKLOG: 'งานค้างในคิวมากผิดปกติ',
  BACKUP_FAILED: 'การสำรองข้อมูลล้มเหลว',
  UPLOAD_MALWARE_DETECTED: 'พบไวรัสในไฟล์อัปโหลด (ระงับแล้ว)',
  UPLOAD_SCAN_UNAVAILABLE: 'ระบบตรวจไวรัสไฟล์อัปโหลดไม่พร้อมใช้งาน',
  LICENSE_EXPIRING: 'สิทธิ์การใช้งานระบบใกล้หมดอายุ',
  LICENSE_GRACE_PERIOD: 'สิทธิ์การใช้งานระบบหมดอายุ (อยู่ในช่วงผ่อนผัน)',
  LICENSE_EXPIRED: 'สิทธิ์การใช้งานระบบหมดอายุ',
}

export function alertTitle(alert) {
  return ALERT_TITLES[alert?.type] || alert?.title || ''
}
