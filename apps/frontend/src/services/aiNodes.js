import api from '@/services/api'

/**
 * AI Node management API client (CAM-003, CAM-014, CAM-017, CAM-018).
 */
export default {
  async listNodes(params = {}) {
    const response = await api.get('/ai-nodes', { params })
    return response.data
  },

  async getNode(uuid) {
    const response = await api.get(`/ai-nodes/${uuid}`)
    return response.data
  },

  async createNode(payload) {
    const response = await api.post('/ai-nodes', payload)
    return response.data
  },

  async updateNode(uuid, payload) {
    const response = await api.put(`/ai-nodes/${uuid}`, payload)
    return response.data
  },

  async updateStatus(uuid, status) {
    const response = await api.patch(`/ai-nodes/${uuid}/status`, { status })
    return response.data
  },

  async getHealth(uuid, params = {}) {
    const response = await api.get(`/ai-nodes/${uuid}/health`, { params })
    return response.data
  },

  async listCredentials(uuid) {
    const response = await api.get(`/ai-nodes/${uuid}/credentials`)
    return response.data
  },

  async issueCredential(uuid, payload = {}) {
    const response = await api.post(`/ai-nodes/${uuid}/credentials`, payload)
    return response.data
  },

  async revokeCredential(uuid, credentialUuid) {
    const response = await api.post(`/ai-nodes/${uuid}/credentials/${credentialUuid}/revoke`)
    return response.data
  },

  // COM-03 allowlisted remote commands
  async listCommands(uuid) {
    const response = await api.get(`/ai-nodes/${uuid}/commands`)
    return response.data
  },

  async sendCommand(uuid, command, reason = null) {
    const response = await api.post(`/ai-nodes/${uuid}/commands`, { command, reason })
    return response.data
  },

  // COM-03 versioned runtime settings
  async listConfigRevisions(uuid) {
    const response = await api.get(`/ai-nodes/${uuid}/config-revisions`)
    return response.data
  },

  async saveConfigRevision(uuid, settings, note = null) {
    const response = await api.post(`/ai-nodes/${uuid}/config-revisions`, { settings, note })
    return response.data
  },

  async rollbackConfig(uuid, revision) {
    const response = await api.post(`/ai-nodes/${uuid}/config-revisions/${revision}/rollback`)
    return response.data
  },
}
