import api from '@/services/api'

/** Visitor and visit APIs. Authorization and feature enforcement remain server-side. */
export const visitorsService = {
  async list(params = {}) {
    const response = await api.get('/visitors', { params })
    return { data: response.data.data, meta: response.data.meta }
  },
  async create(payload) {
    const response = await api.post('/visitors', payload)
    return response.data.data
  },
  async update(uuid, payload) {
    const response = await api.put(`/visitors/${uuid}`, payload)
    return response.data.data
  },
  async visits(params = {}) {
    const response = await api.get('/visits', { params })
    return { data: response.data.data, meta: response.data.meta }
  },
  async createVisit(payload) {
    const response = await api.post('/visits', payload)
    return response.data.data
  },
  async checkIn(uuid) {
    const response = await api.post(`/visits/${uuid}/check-in`)
    return response.data.data
  },
  async checkOut(uuid) {
    const response = await api.post(`/visits/${uuid}/check-out`)
    return response.data.data
  },
  async cancel(uuid) {
    const response = await api.post(`/visits/${uuid}/cancel`)
    return response.data.data
  },
}
