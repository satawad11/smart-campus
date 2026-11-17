import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { flushPromises, mount } from '@vue/test-utils'
import api from '@/services/api'
import { guardiansService, studentsService, personsService } from '@/services/students'
import { visitorsService } from '@/services/visitors'
import camerasService from '@/services/cameras'
import { useAuthStore } from '@/stores/auth'
import GuardiansView from '@/views/people/GuardiansView.vue'
import VisitorsView from '@/views/people/VisitorsView.vue'

describe('PPL-011 people and visitor UI', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.restoreAllMocks()
  })

  it('provides guardian directory creation and student link actions', async () => {
    const auth = useAuthStore()
    auth.user = { permissions: ['guardian.view', 'guardian.manage', 'student.view'] }
    vi.spyOn(guardiansService, 'list').mockResolvedValue({
      data: [
        {
          uuid: 'parent-1',
          name: 'สมใจ ใจดี',
          person_type: 'PARENT',
          person_code: null,
          status: 'ACTIVE',
        },
      ],
    })
    vi.spyOn(studentsService, 'list').mockResolvedValue({
      data: [
        {
          uuid: 'student-1',
          student_code: 'S-1',
          person: { first_name: 'สมชาย', last_name: 'ใจดี' },
        },
      ],
    })
    const create = vi.spyOn(guardiansService, 'create').mockResolvedValue({ uuid: 'parent-2' })
    const link = vi.spyOn(guardiansService, 'link').mockResolvedValue({ uuid: 'link-1' })
    const wrapper = mount(GuardiansView, { global: { stubs: { RouterLink: true } } })
    await flushPromises()
    expect(wrapper.text()).toContain('สมใจ ใจดี')
    await wrapper.get('input[required]').setValue('อรุณ')
    await wrapper.findAll('input[required]')[1].setValue('ทดสอบ')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(create).toHaveBeenCalled()
    await wrapper.get('select').setValue('parent-1')
    await wrapper.findAll('select')[1].setValue('student-1')
    await wrapper.findAll('form')[1].trigger('submit')
    await flushPromises()
    expect(link).toHaveBeenCalledWith('student-1', {
      guardian_person_uuid: 'parent-1',
      relation: 'GUARDIAN',
    })
  })

  it('fails closed without requesting visitor or visit data when feature is disabled', async () => {
    vi.spyOn(api, 'get').mockResolvedValue({ data: { data: { visitor: false } } })
    const list = vi.spyOn(visitorsService, 'list')
    const wrapper = mount(VisitorsView)
    await flushPromises()
    expect(wrapper.text()).toContain('ฟังก์ชันผู้มาติดต่อยังปิดอยู่')
    expect(list).not.toHaveBeenCalled()
  })

  it('loads today visits and enables check-in/out only for valid visit states', async () => {
    vi.spyOn(api, 'get').mockResolvedValue({ data: { data: { visitor: true } } })
    vi.spyOn(visitorsService, 'list').mockResolvedValue({ data: [] })
    vi.spyOn(visitorsService, 'visits').mockResolvedValue({
      data: [
        {
          uuid: 'scheduled-1',
          visitor: { name: 'ผู้มาติดต่อ', person_type: 'VISITOR' },
          purpose: 'ติดต่อ',
          status: 'SCHEDULED',
          valid_from: '2026-11-17T01:00:00Z',
          valid_until: '2026-11-17T02:00:00Z',
        },
        {
          uuid: 'checked-in-1',
          visitor: { name: 'ผู้มาติดต่อ 2', person_type: 'CONTRACTOR' },
          purpose: 'ซ่อม',
          status: 'CHECKED_IN',
          valid_from: '2026-11-17T01:00:00Z',
          valid_until: '2026-11-17T02:00:00Z',
        },
      ],
    })
    vi.spyOn(camerasService, 'listCameras').mockResolvedValue({ data: [] })
    const checkIn = vi.spyOn(visitorsService, 'checkIn').mockResolvedValue({})
    const checkOut = vi.spyOn(visitorsService, 'checkOut').mockResolvedValue({})
    const auth = useAuthStore()
    auth.user = { permissions: ['visitor.view', 'visitor.manage', 'visit.manage', 'visit.view'] }
    const wrapper = mount(VisitorsView, { global: { stubs: { RouterLink: true } } })
    await flushPromises()
    expect(visitorsService.visits).toHaveBeenCalled()
    expect(wrapper.findAll('button').some((b) => b.text() === 'เช็กอิน')).toBe(true)
    expect(wrapper.findAll('button').some((b) => b.text() === 'เช็กเอาต์')).toBe(true)
    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'เช็กอิน')
      .trigger('click')
    await flushPromises()
    expect(checkIn).toHaveBeenCalledWith('scheduled-1')
    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'เช็กเอาต์')
      .trigger('click')
    await flushPromises()
    expect(checkOut).toHaveBeenCalledWith('checked-in-1')
  })

  it('uses the person type filter for face-enrollment search', async () => {
    const get = vi
      .spyOn(api, 'get')
      .mockResolvedValue({ data: { data: [{ uuid: 'p-1', person_type: 'PARENT' }], meta: null } })
    const result = await personsService.list({
      keyword: 'สมใจ',
      person_type: 'PARENT',
      per_page: 20,
    })
    expect(result.data[0].person_type).toBe('PARENT')
    expect(get).toHaveBeenCalledWith('/persons', {
      params: { keyword: 'สมใจ', person_type: 'PARENT', per_page: 20 },
    })
  })
})
