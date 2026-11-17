import api from '@/services/api'

/** Staff API response envelope helpers. */
const staffService = {
  async list(params = {}) { const { data } = await api.get('/staff', { params }); return data },
  async get(uuid) { const { data } = await api.get(`/staff/${uuid}`); return data },
  async create(payload) { const { data } = await api.post('/staff', payload); return data },
  async update(uuid, payload) { const { data } = await api.put(`/staff/${uuid}`, payload); return data },
  async changeStatus(uuid, status, reason = null) {
    const body = typeof status === 'object' ? status : { status, reason }
    const { data } = await api.patch(`/staff/${uuid}/status`, body); return data
  },
  async statusHistory(uuid, params = {}) { const { data } = await api.get(`/staff/${uuid}/status-history`, { params }); return data },
  async uploadImport(file, conflictMode = 'CREATE_ONLY') {
    const form = new FormData(); form.append('file', file); form.append('conflict_mode', conflictMode)
    const { data } = await api.post('/staff/import', form, { headers: { 'Content-Type': 'multipart/form-data' } }); return data
  },
  async getImport(uuid) { const { data } = await api.get(`/staff/imports/${uuid}`); return data },
  async importPreview(uuid) { const { data } = await api.get(`/staff/imports/${uuid}/preview`); return data },
  async importErrors(uuid, params = { per_page: 100 }) { const { data } = await api.get(`/staff/imports/${uuid}/errors`, { params }); return data },
  async processImport(uuid) { const { data } = await api.post(`/staff/imports/${uuid}/process`); return data },
}

// Named exports keep the explicit API contract used by the focused PPL-010 tests.
export { staffService }
export const staffImportService = {
  upload(formData) { return api.post('/staff/import', formData, { headers: { 'Content-Type': 'multipart/form-data' } }).then(r => r.data.data) },
  show(uuid) { return api.get(`/staff/imports/${uuid}`).then(r => r.data.data) },
  preview(uuid) { return api.get(`/staff/imports/${uuid}/preview`).then(r => r.data) },
  errors(uuid, params = {}) { return api.get(`/staff/imports/${uuid}/errors`, { params }).then(r => ({ data: r.data.data, meta: r.data.meta })) },
  process(uuid) { return api.post(`/staff/imports/${uuid}/process`).then(r => r.data.data) },
}
export default staffService
