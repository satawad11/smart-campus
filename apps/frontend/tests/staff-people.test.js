import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import api from '@/services/api'
import router from '@/router'
import { staffImportService, staffService } from '@/services/staff'
import StaffListView from '@/views/staff/StaffListView.vue'
import { useAuthStore } from '@/stores/auth'

describe('PPL-010 staff management UI', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('registers dedicated staff list, create, detail, edit, and import routes', () => {
    const routes = router.getRoutes()
    expect(routes.find((route) => route.name === 'staff.index')?.path).toBe('/staff')
    expect(routes.map((route) => route.name)).toEqual(
      expect.arrayContaining(['staff.create', 'staff.show', 'staff.edit', 'staff.import']),
    )
  })

  it('uses staff endpoints and returns the API pagination contract', async () => {
    const get = vi.spyOn(api, 'get').mockResolvedValue({
      data: { data: [{ uuid: 'staff-uuid' }], meta: { pagination: { current_page: 1 } } },
    })
    const result = await staffService.list({ person_type: 'TEACHER' })
    expect(get).toHaveBeenCalledWith('/staff', { params: { person_type: 'TEACHER' } })
    expect(result.data[0].uuid).toBe('staff-uuid')
    expect(result.meta.pagination.current_page).toBe(1)
  })

  it('starts a validated import without overriding its uploaded conflict policy', async () => {
    const post = vi
      .spyOn(api, 'post')
      .mockResolvedValue({ data: { data: { status: 'PROCESSING' } } })
    await staffImportService.process('import-uuid')
    expect(post).toHaveBeenCalledWith('/staff/imports/import-uuid/process')
  })

  it('renders staff separately, with search, employment filters, and records', async () => {
    const auth = useAuthStore()
    auth.user = { uuid: 'admin', permissions: ['staff.view', 'staff.create', 'staff.import'] }
    vi.spyOn(staffService, 'list').mockResolvedValue({
      data: [
        {
          uuid: 'staff-uuid',
          staff_code: 'T-001',
          employment_status: 'ACTIVE',
          position: 'ครู',
          person: { person_type: 'TEACHER', first_name: 'สมชาย', last_name: 'ใจดี' },
          department: { name: 'ช่างไฟฟ้า' },
        },
      ],
      meta: { pagination: { current_page: 1, last_page: 1, total: 1 } },
    })
    const wrapper = mount(StaffListView, { global: { plugins: [router] } })
    await flushPromises()
    expect(wrapper.text()).toContain('จัดการข้อมูลบุคลากร')
    expect(wrapper.text()).toContain('สมชาย ใจดี')
    expect(wrapper.text()).toContain('ช่างไฟฟ้า')
    expect(wrapper.find('input[aria-label="ค้นหาบุคลากร"]').exists()).toBe(true)
  })
})
