import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import camerasService from '@/services/cameras'
import CameraZoneWall from '@/components/cameras/CameraZoneWall.vue'

const CAMERAS = [
  { uuid: 'c1', name: 'ประตูหน้า', status: 'ACTIVE', zone: { uuid: 'z1', name: 'ทางเข้า' } },
  { uuid: 'c2', name: 'ประตูหลัง', status: 'ACTIVE', zone: { uuid: 'z1', name: 'ทางเข้า' } },
  { uuid: 'c3', name: 'โรงอาหาร', status: 'INACTIVE', zone: { uuid: 'z2', name: 'อาคาร 2' } },
  { uuid: 'c4', name: 'ลานจอด', status: 'ACTIVE', zone: null },
]

describe('EVT-027: camera wall grouped by zone', () => {
  let snapshot

  beforeEach(() => {
    vi.restoreAllMocks()
    snapshot = vi.spyOn(camerasService, 'captureSnapshot').mockResolvedValue({
      data: { content_type: 'image/jpeg', image_base64: 'AAAA' },
    })
  })

  function mountWall(props = {}) {
    return mount(CameraZoneWall, {
      props: { cameras: CAMERAS, canViewLive: true, intervalMs: 60000, ...props },
    })
  }

  it('groups every camera under its zone, with a tab per zone', async () => {
    const wrapper = mountWall()
    await flushPromises()

    expect(wrapper.findAll('[data-test="wall-tile"]')).toHaveLength(4)
    expect(wrapper.get('[data-test="zone-section-z1"]').text()).toContain('2 กล้อง')
    expect(wrapper.get('[data-test="zone-section-__none__"]').text()).toContain('ไม่ระบุโซน')
    expect(wrapper.get('[data-test="zone-tab-all"]').text()).toContain('4')

    await wrapper.get('[data-test="zone-tab-z1"]').trigger('click')
    expect(wrapper.findAll('[data-test="wall-tile"]')).toHaveLength(2)
    expect(wrapper.find('[data-test="zone-section-z2"]').exists()).toBe(false)
  })

  it('starts live frames for active cameras only, and all of them on request', async () => {
    const wrapper = mountWall()
    await flushPromises()

    const started = snapshot.mock.calls.map((c) => c[0]).sort()
    expect(started).toEqual(['c1', 'c2', 'c4'])
    expect(wrapper.findAll('[data-testid="live-frame"]')).toHaveLength(3)

    snapshot.mockClear()
    await wrapper.get('[data-test="wall-start-all"]').trigger('click')
    await flushPromises()
    expect(snapshot.mock.calls.map((c) => c[0]).sort()).toEqual(['c1', 'c2', 'c3', 'c4'])

    wrapper.unmount()
  })

  it('lists cameras without pulling frames when camera.live is missing', async () => {
    const wrapper = mountWall({ canViewLive: false })
    await flushPromises()

    expect(wrapper.findAll('[data-test="wall-tile"]')).toHaveLength(4)
    expect(wrapper.find('[data-test="wall-no-live"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="wall-start-all"]').exists()).toBe(false)
    expect(snapshot).not.toHaveBeenCalled()
  })
})
