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
