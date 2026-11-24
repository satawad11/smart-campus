import api from '@/services/api'

/**
 * Smart Gate configuration API client (GATE-002, ACR-VEH-01).
 */
export default {
  async listGates(params = {}) {
    const response = await api.get('/gates', { params })
    return response.data
  },

  async getGate(uuid) {
    const response = await api.get(`/gates/${uuid}`)
    return response.data
  },

  /** Sites with their zones and cameras (each camera says which gate already uses it). */
  async getFormOptions() {
    const response = await api.get('/gates/form-options')
    return response.data
  },

  async createGate(payload) {
    const response = await api.post('/gates', payload)
    return response.data
  },

  async updateGate(uuid, payload) {
    const response = await api.put(`/gates/${uuid}`, payload)
    return response.data
  },

  /** Gate pass history (GATE-008): report.view, read only. */
  async listPasses(params = {}) {
    const response = await api.get('/history/gate-passes', { params })
    return response.data
  },

  /** Replace the whole camera set: [{ camera_uuid, role }]. */
  async replaceCameras(uuid, cameras) {
    const response = await api.put(`/gates/${uuid}/cameras`, { cameras })
    return response.data
  },
}
