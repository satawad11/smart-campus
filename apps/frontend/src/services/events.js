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

  /**
   * EVT-025: the event's face crop (audited on the server, never cached).
   * Returns { data: { content_type, image_base64 } }.
   */
  async getEventSnapshot(uuid) {
    const response = await api.get(`/events/${uuid}/snapshot`)
    return response.data
  },

  async reviewEvent(uuid, payload) {
    const response = await api.post(`/events/${uuid}/review`, payload)
    return response.data
  },
}
