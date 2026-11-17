import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'

import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import LocationsView from '@/views/locations/LocationsView.vue'

const site = {
  uuid: 's1',
  code: 'MAIN',
  name: 'อาคารหลัก',
  address: null,
  timezone: 'Asia/Bangkok',
  status: 'ACTIVE',
  zones_count: 1,
  zones: [
    {
      uuid: 'z1',
      code: 'GATE-1',
      name: 'ประตูหน้า',
      zone_type: 'GATE',
      status: 'ACTIVE',
      cameras_count: 2,
    },
  ],
}

function signIn(permissions) {
  useAuthStore().user = { uuid: 'u1', username: 'admin', permissions }
}

describe('Locations page (sites and zones)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.restoreAllMocks()
    vi.spyOn(api, 'get').mockResolvedValue({ data: { data: [site] } })
  })

  it('lists sites with their zones and camera counts, read-only without system.manage', async () => {
    signIn(['system.view'])
    const wrapper = mount(LocationsView)
    await flushPromises()

    expect(wrapper.findAll('[data-test="site-card"]')).toHaveLength(1)
    const zone = wrapper.find('[data-test="zone-row"]').text()
    expect(zone).toContain('ประตูหน้า')
    expect(zone).toContain('ประตู / ทางเข้า-ออก')
    expect(zone).toContain('2')
    expect(wrapper.find('[data-test="site-add"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="zone-edit"]').exists()).toBe(false)
  })

  it('adds a zone to a site', async () => {
    signIn(['system.view', 'system.manage'])
    const postSpy = vi.spyOn(api, 'post').mockResolvedValue({ data: { data: site } })
    const wrapper = mount(LocationsView)
    await flushPromises()

    await wrapper.find('[data-test="zone-add"]').trigger('click')
    await wrapper.find('[data-test="loc-code"]').setValue('GATE-2')
    await wrapper.find('[data-test="loc-name"]').setValue('ประตูหลัง')
    await wrapper.find('[data-test="location-form"]').trigger('submit')
    await flushPromises()

    expect(postSpy).toHaveBeenCalledWith('/sites/s1/zones', {
      code: 'GATE-2',
      name: 'ประตูหลัง',
      zone_type: 'GATE',
    })
    expect(wrapper.find('[data-test="location-form"]').exists()).toBe(false)
  })

  it('edits a site without sending its code, and can deactivate it', async () => {
    signIn(['system.view', 'system.manage'])
    const putSpy = vi.spyOn(api, 'put').mockResolvedValue({ data: { data: site } })
    const wrapper = mount(LocationsView)
    await flushPromises()

    await wrapper.find('[data-test="site-edit"]').trigger('click')
    expect(wrapper.find('[data-test="loc-code"]').attributes('disabled')).toBeDefined()
    await wrapper.find('[data-test="loc-name"]').setValue('อาคาร 1')
    await wrapper.find('[data-test="loc-status"]').setValue('INACTIVE')
    await wrapper.find('[data-test="location-form"]').trigger('submit')
    await flushPromises()

    expect(putSpy).toHaveBeenCalledWith('/sites/s1', {
      name: 'อาคาร 1',
      address: null,
      timezone: 'Asia/Bangkok',
      status: 'INACTIVE',
    })
  })
})
