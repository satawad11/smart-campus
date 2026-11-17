import api from './api'

export const staffService = {
  async list(params = {}) {
    const response = await api.get('/staff', { params })
    return { data: response.data.data, meta: response.data.meta }
  },
  async get(uuid) {
    const response = await api.get(`/staff/${uuid}`)
    return response.data.data
  },
  async create(payload) {
    const response = await api.post('/staff', payload)
    return response.data.data
  },
  async update(uuid, payload) {
    const response = await api.put(`/staff/${uuid}`, payload)
    return response.data.data
  },
  async changeStatus(uuid, payload) {
    const response = await api.patch(`/staff/${uuid}/status`, payload)
    return response.data.data
  },
  async statusHistory(uuid, params = {}) {
    const response = await api.get(`/staff/${uuid}/status-history`, { params })
    return { data: response.data.data, meta: response.data.meta }
  },
}

export const staffImportService = {
  async upload(formData) {
    const response = await api.post('/staff/import', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    return response.data.data
  },
  async show(uuid) {
    const response = await api.get(`/staff/imports/${uuid}`)
    return response.data.data
  },
  async preview(uuid) {
    const response = await api.get(`/staff/imports/${uuid}/preview`)
    return response.data
  },
  async errors(uuid, params = {}) {
    const response = await api.get(`/staff/imports/${uuid}/errors`, { params })
    return { data: response.data.data, meta: response.data.meta }
  },
  async process(uuid) {
    const response = await api.post(`/staff/imports/${uuid}/process`)
    return response.data.data
  },
}
