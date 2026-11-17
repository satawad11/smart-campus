import api from '@/services/api'

/**
 * Teachers and staff (Module 09, PPL-003/004 API, PPL-010 UI). Public ids are uuids.
 */
export default {
  async list(params = {}) {
    const response = await api.get('/staff', { params })
    return response.data
  },
  async get(uuid) {
    const response = await api.get(`/staff/${uuid}`)
    return response.data
  },
  async create(payload) {
    const response = await api.post('/staff', payload)
    return response.data
  },
  async update(uuid, payload) {
    const response = await api.put(`/staff/${uuid}`, payload)
    return response.data
  },
  async changeStatus(uuid, status, reason = null) {
    const response = await api.patch(`/staff/${uuid}/status`, { status, reason })
    return response.data
  },
  async statusHistory(uuid, params = {}) {
    const response = await api.get(`/staff/${uuid}/status-history`, { params })
    return response.data
  },

  // CSV import: upload -> validate -> preview -> process (PPL-004).
  async uploadImport(file, conflictMode = 'CREATE_ONLY') {
    const form = new FormData()
    form.append('file', file)
    form.append('conflict_mode', conflictMode)
    const response = await api.post('/staff/import', form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    return response.data
  },
  async getImport(uuid) {
    const response = await api.get(`/staff/imports/${uuid}`)
    return response.data
  },
  async importPreview(uuid) {
    const response = await api.get(`/staff/imports/${uuid}/preview`)
    return response.data
  },
  async importErrors(uuid, params = { per_page: 100 }) {
    const response = await api.get(`/staff/imports/${uuid}/errors`, { params })
    return response.data
  },
  async processImport(uuid) {
    const response = await api.post(`/staff/imports/${uuid}/process`)
    return response.data
  },
}
