import api from '@/services/api'

/**
 * Event history / review API client (EVT-008/EVT-009/EVT-010).
 */
export default {
  async listEvents(params = {}) {
    const response = await api.get('/events', { params })
    return response.data
  },

  async getEvent(uuid) {
    const response = await api.get(`/events/${uuid}`)
    return response.data
  },

  async reviewEvent(uuid, payload) {
    const response = await api.post(`/events/${uuid}/review`, payload)
    return response.data
  },
}
