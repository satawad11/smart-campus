// Labels for the staff pages (Module 09).

export const STAFF_TYPES = {
  TEACHER: 'ครู',
  STAFF: 'บุคลากร',
}

export const EMPLOYMENT_STATUSES = {
  ACTIVE: 'ปฏิบัติงาน',
  LEAVE: 'ลา',
  SUSPENDED: 'พักงาน',
  RESIGNED: 'ลาออก',
  RETIRED: 'เกษียณ',
}

export function employmentBadge(status) {
  return (
    {
      ACTIVE: 'bg-green-100 text-green-800',
      LEAVE: 'bg-amber-100 text-amber-800',
      SUSPENDED: 'bg-red-100 text-red-800',
    }[status] || 'bg-gray-100 text-gray-600'
  )
}

export function fullName(person) {
  if (!person) return '-'
  return person.display_name || `${person.first_name || ''} ${person.last_name || ''}`.trim() || '-'
}
