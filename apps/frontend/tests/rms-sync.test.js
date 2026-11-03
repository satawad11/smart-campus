import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import api from '@/services/api'
import { rmsSyncService } from '@/services/students'
import RmsSyncView from '@/views/students/RmsSyncView.vue'

describe('ACR-INT-01: RMS sync page', () => {
  const completedRun = {
    uuid: 'run-1',
    status: 'COMPLETED',
    trigger: 'MANUAL',
    dry_run: true,
    counts: { students_created: 1300, staff_created: 184, students_missing_from_rms: 0 },
    issue_summary: [{ code: 'MAJOR_NOT_MAPPED', count: 1 }],
    issues: null,
    error: null,
    created_by: 'admin',
    started_at: '2026-11-03T05:30:00Z',
    finished_at: '2026-11-03T05:31:00Z',
  }

  beforeEach(() => {
    vi.restoreAllMocks()
    vi.spyOn(rmsSyncService, 'list').mockResolvedValue({
      data: [completedRun],
      meta: {
        configured: true,
        schedule_enabled: false,
        schedule_cron: '30 5 * * *',
        running: false,
      },
    })
  })

  afterEach(() => vi.useRealTimers())

  it('service uses the sync-runs endpoints', async () => {
    const post = vi.spyOn(api, 'post').mockResolvedValue({ data: { data: { queued: true } } })
    const get = vi.spyOn(api, 'get').mockResolvedValue({ data: { data: {} } })
    await rmsSyncService.start(true)
    await rmsSyncService.show('run-1')
    expect(post).toHaveBeenCalledWith('/integrations/rms/sync-runs', { dry_run: true })
    expect(get).toHaveBeenCalledWith('/integrations/rms/sync-runs/run-1')
  })

  it('lists runs with a Thai summary and opens the report', async () => {
    vi.spyOn(rmsSyncService, 'show').mockResolvedValue({
      ...completedRun,
      issues: [{ code: 'MAJOR_NOT_MAPPED', count: 1, items: ['การบัญชี -> แผนกวิชาการบัญชี'] }],
    })
    const wrapper = mount(RmsSyncView)
    await flushPromises()

    const row = wrapper.find('[data-test="run-row"]')
    expect(row.text()).toContain('ทดลอง')
    expect(row.text()).toContain('ใหม่ 1484')
    expect(wrapper.text()).toContain('ปิด (เปิดได้หลังตรวจผลทดลองซิงก์)')

    await row
      .findAll('button')
      .find((b) => b.text() === 'ดูรายงาน')
      .trigger('click')
    await flushPromises()
    const report = wrapper.find('[data-test="report"]')
    expect(report.text()).toContain('นักเรียนใหม่')
    expect(report.text()).toContain('1300')
    expect(report.text()).toContain('การบัญชี -> แผนกวิชาการบัญชี')
  })

  it('a dry run starts without confirmation; a real run asks first', async () => {
    const start = vi.spyOn(rmsSyncService, 'start').mockResolvedValue({ queued: true })
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false)
    const wrapper = mount(RmsSyncView)
    await flushPromises()

    await wrapper.find('[data-test="real-run"]').trigger('click')
    expect(confirm).toHaveBeenCalled()
    expect(start).not.toHaveBeenCalled()

    await wrapper.find('[data-test="dry-run"]').trigger('click')
    await flushPromises()
    expect(start).toHaveBeenCalledWith(true)
    expect(wrapper.text()).toContain('เริ่มทดลองซิงก์แล้ว')
  })

  it('disables both buttons when the RMS key is not configured', async () => {
    rmsSyncService.list.mockResolvedValue({ data: [], meta: { configured: false } })
    const wrapper = mount(RmsSyncView)
    await flushPromises()

    expect(wrapper.find('[data-test="dry-run"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-test="real-run"]').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('ยังไม่ได้ตั้งค่ากุญแจ RMS')
  })
})
