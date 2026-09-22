import api from '@/services/api'

/**
 * Camera management API client (CAM-002, CAM-013, CAM-016, CAM-017).
 */
export default {
  async listCameras(params = {}) {
    const response = await api.get('/cameras', { params })
    return response.data
  },

  async getCamera(uuid) {
    const response = await api.get(`/cameras/${uuid}`)
    return response.data
  },

  async createCamera(payload) {
    const response = await api.post('/cameras', payload)
    return response.data
  },

  async updateCamera(uuid, payload) {
    const response = await api.put(`/cameras/${uuid}`, payload)
    return response.data
  },

  async updateStatus(uuid, status) {
    const response = await api.patch(`/cameras/${uuid}/status`, { status })
    return response.data
  },

  async testConnection(uuid) {
    const response = await api.post(`/cameras/${uuid}/test`)
    return response.data
  },

  async captureSnapshot(uuid) {
    const response = await api.post(`/cameras/${uuid}/snapshot`)
    return response.data
  },

  async getHealth(uuid, params = {}) {
    const response = await api.get(`/cameras/${uuid}/health`, { params })
    return response.data
  },

  async updateRoi(uuid, points) {
    const response = await api.patch(`/cameras/${uuid}/roi`, { points })
    return response.data
  },
}
