import api from '@/services/api'

/**
 * Personal notification inbox (EVT-012 API, EVT-022 UI). Always the signed-in
 * user's own notifications; the backend scopes every call.
 */
export default {
  async list(params = {}) {
    const response = await api.get('/notifications', { params })
    return response.data
  },

  async markRead(uuid) {
    const response = await api.patch(`/notifications/${uuid}/read`)
    return response.data
  },

  async markAllRead() {
    const response = await api.post('/notifications/read-all')
    return response.data
  },
}
