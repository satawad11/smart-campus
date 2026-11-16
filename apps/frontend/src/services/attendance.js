import api from '@/services/api'

/**
 * Daily time-in / time-out (GET /reports/attendance), built from ALLOW
 * recognition events. Params: date (YYYY-MM-DD), person_type, keyword, site_uuid.
 */
export default {
  async daily(params = {}) {
    const response = await api.get('/reports/attendance', { params })
    return response.data
  },
}
