import api from '@/services/api'

/**
 * Sites and zones (UIX-002). Read: system.view. Write: system.manage.
 * Codes are fixed after creation; deactivate instead of deleting.
 */
export default {
  async listSites(params = {}) {
    const response = await api.get('/sites', { params })
    return response.data
  },
  async createSite(payload) {
    const response = await api.post('/sites', payload)
    return response.data
  },
  async updateSite(uuid, payload) {
    const response = await api.put(`/sites/${uuid}`, payload)
    return response.data
  },
  async createZone(siteUuid, payload) {
    const response = await api.post(`/sites/${siteUuid}/zones`, payload)
    return response.data
  },
  async updateZone(uuid, payload) {
    const response = await api.put(`/zones/${uuid}`, payload)
    return response.data
  },
}
