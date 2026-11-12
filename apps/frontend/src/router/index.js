import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

import AuthLayout from '@/layouts/AuthLayout.vue'
import AdminLayout from '@/layouts/AdminLayout.vue'

const routes = [
  {
    path: '/login',
    component: AuthLayout,
    children: [
      {
        path: '',
        name: 'login',
        component: () => import('@/views/LoginView.vue'),
        meta: { guestOnly: true },
      },
    ],
  },
  {
    path: '/mfa',
    component: AuthLayout,
    children: [
      {
        path: '',
        name: 'mfa',
        component: () => import('@/views/MfaView.vue'),
        meta: { requiresMfaPending: true },
      },
    ],
  },
  {
    path: '/change-password',
    component: AuthLayout,
    children: [
      {
        path: '',
        name: 'change-password',
        component: () => import('@/views/ChangePasswordView.vue'),
        meta: { requiresAuth: true },
      },
    ],
  },
  {
    path: '/',
    component: AdminLayout,
    meta: { requiresAuth: true },
    children: [
      { path: '', redirect: { name: 'dashboard' } },
      {
        path: 'dashboard',
        name: 'dashboard',
        component: () => import('@/views/DashboardView.vue'),
        meta: { requiresAuth: true },
      },
      {
        path: 'users',
        name: 'users.index',
        component: () => import('@/views/users/UserListView.vue'),
        meta: { requiresAuth: true, permission: 'user.view' },
      },
      {
        path: 'users/new',
        name: 'users.create',
        component: () => import('@/views/users/UserCreateView.vue'),
        meta: { requiresAuth: true, permission: 'user.create' },
      },
      {
        path: 'users/:uuid',
        name: 'users.show',
        component: () => import('@/views/users/UserDetailView.vue'),
        meta: { requiresAuth: true, permission: 'user.view' },
      },
      {
        path: 'users/:uuid/edit',
        name: 'users.edit',
        component: () => import('@/views/users/UserEditView.vue'),
        meta: { requiresAuth: true, permission: 'user.update' },
      },
      {
        path: 'students',
        name: 'students.index',
        component: () => import('@/views/students/StudentListView.vue'),
        meta: { requiresAuth: true, permission: 'student.view' },
      },
      {
        path: 'students/new',
        name: 'students.create',
        component: () => import('@/views/students/StudentCreateView.vue'),
        meta: { requiresAuth: true, permission: 'student.create' },
      },
      {
        path: 'students/create',
        redirect: { name: 'students.create' },
      },
      {
        path: 'students/import',
        name: 'students.import',
        component: () => import('@/views/students/StudentImportView.vue'),
        meta: { requiresAuth: true, permission: 'student.import' },
      },
      {
        path: 'students/rms-sync',
        name: 'students.rmsSync',
        component: () => import('@/views/students/RmsSyncView.vue'),
        meta: { requiresAuth: true, permission: 'student.sync' },
      },
      {
        path: 'students/:uuid',
        name: 'students.show',
        component: () => import('@/views/students/StudentDetailView.vue'),
        meta: { requiresAuth: true, permission: 'student.view' },
      },
      {
        path: 'students/:uuid/edit',
        name: 'students.edit',
        component: () => import('@/views/students/StudentEditView.vue'),
        meta: { requiresAuth: true, permission: 'student.update' },
      },
      {
        path: 'roles',

        name: 'roles.index',
        component: () => import('@/views/roles/RoleListView.vue'),
        meta: { requiresAuth: true, permission: 'role.view' },
      },
      {
        path: 'roles/new',
        name: 'roles.create',
        component: () => import('@/views/roles/RoleCreateView.vue'),
        meta: { requiresAuth: true, permission: 'role.manage' },
      },
      {
        path: 'roles/:uuid',
        name: 'roles.show',
        component: () => import('@/views/roles/RoleDetailView.vue'),
        meta: { requiresAuth: true, permission: 'role.view' },
      },
      {
        path: 'roles/:uuid/edit',
        name: 'roles.edit',
        component: () => import('@/views/roles/RoleEditView.vue'),
        meta: { requiresAuth: true, permission: 'role.manage' },
      },
      {
        path: 'roles/:uuid/permissions',
        name: 'roles.permissions',
        component: () => import('@/views/roles/RolePermissionsView.vue'),
        meta: { requiresAuth: true, permission: 'role.view' },
      },
      {
        path: 'faces',
        name: 'faces.index',
        component: () => import('@/views/face/FaceProfileListView.vue'),
        meta: { requiresAuth: true, permission: 'face.view' },
      },
      {
        path: 'faces/enroll',
        name: 'faces.enroll',
        component: () => import('@/views/face/FaceEnrollmentView.vue'),
        meta: { requiresAuth: true, permission: 'face.enroll' },
      },
      {
        path: 'faces/test',
        name: 'faces.test',
        component: () => import('@/views/face/RecognitionTestView.vue'),
        meta: { requiresAuth: true, permission: 'face.test' },
      },
      {
        path: 'faces/:uuid',
        name: 'faces.show',
        component: () => import('@/views/face/FaceProfileDetailView.vue'),
        meta: { requiresAuth: true, permission: 'face.view' },
      },
      {
        path: 'cameras',
        name: 'cameras.index',
        component: () => import('@/views/cameras/CameraListView.vue'),
        meta: { requiresAuth: true, permission: 'camera.view' },
      },
      {
        path: 'cameras/create',
        name: 'cameras.create',
        component: () => import('@/views/cameras/CameraCreateView.vue'),
        meta: { requiresAuth: true, permission: 'camera.create' },
      },
      {
        path: 'cameras/:uuid',
        name: 'cameras.show',
        component: () => import('@/views/cameras/CameraDetailView.vue'),
        meta: { requiresAuth: true, permission: 'camera.view' },
      },
      {
        path: 'ai-nodes',
        name: 'ai-nodes.index',
        component: () => import('@/views/aiNodes/AiNodeListView.vue'),
        meta: { requiresAuth: true, permission: 'ai_node.view' },
      },
      {
        path: 'ai-nodes/create',
        name: 'ai-nodes.create',
        component: () => import('@/views/aiNodes/AiNodeCreateView.vue'),
        meta: { requiresAuth: true, permission: 'ai_node.manage' },
      },
      {
        path: 'ai-nodes/:uuid',
        name: 'ai-nodes.show',
        component: () => import('@/views/aiNodes/AiNodeDetailView.vue'),
        meta: { requiresAuth: true, permission: 'ai_node.view' },
      },
      {
        path: 'health',
        name: 'health.overview',
        component: () => import('@/views/HealthOverviewView.vue'),
        meta: { requiresAuth: true },
      },
      {
        path: 'monitor',
        name: 'monitor.live',
        component: () => import('@/views/monitor/LiveMonitorView.vue'),
        meta: { requiresAuth: true, permission: 'monitor.view' },
      },
      {
        path: 'events',
        name: 'events.index',
        component: () => import('@/views/events/EventListView.vue'),
        meta: { requiresAuth: true, permission: 'event.view' },
      },
      {
        path: 'events/:uuid',
        name: 'events.show',
        component: () => import('@/views/events/EventDetailView.vue'),
        meta: { requiresAuth: true, permission: 'event.view' },
      },
      {
        path: 'unknown-persons',
        name: 'unknown-persons.index',
        component: () => import('@/views/events/UnknownPersonsView.vue'),
        meta: { requiresAuth: true, permission: 'event.view' },
      },
      {
        path: 'history',
        name: 'history.search',
        component: () => import('@/views/history/HistorySearchView.vue'),
        meta: { requiresAuth: true, permission: 'report.view' },
      },
      {
        path: 'persons/:uuid/timeline',
        name: 'persons.timeline',
        component: () => import('@/views/history/PersonTimelineView.vue'),
        meta: { requiresAuth: true, permission: 'report.view' },
      },
      {
        path: 'reports',
        name: 'reports.index',
        component: () => import('@/views/reports/ReportsView.vue'),
        meta: { requiresAuth: true, permission: 'report.view' },
      },
      {
        path: 'settings',
        name: 'settings.index',
        component: () => import('@/views/settings/SystemSettingsView.vue'),
        meta: { requiresAuth: true, permission: 'system.view' },
      },
      {
        // ACR-INT-02: external API connections (RMS). Edit needs integration.manage.
        path: 'integrations',
        name: 'integrations.index',
        component: () => import('@/views/system/IntegrationsView.vue'),
        meta: { requiresAuth: true, permission: 'system.view' },
      },
      {
        path: 'system/health',
        name: 'system.health',
        component: () => import('@/views/system/SystemHealthView.vue'),
        meta: { requiresAuth: true, permission: 'system.view' },
      },
      {
        path: 'system/incidents',
        name: 'system.incidents',
        component: () => import('@/views/system/IncidentsView.vue'),
        meta: { requiresAuth: true, permission: 'system.view' },
      },
      {
        path: 'audit-logs',
        name: 'audit-logs.index',
        component: () => import('@/views/system/AuditLogView.vue'),
        meta: { requiresAuth: true, permission: 'audit.view' },
      },
      {
        path: 'system/license',
        name: 'system.license',
        component: () => import('@/views/system/LicenseView.vue'),
        meta: { requiresAuth: true, permission: 'system.view' },
      },
      {
        // ACR-OPS-01: SUPER_ADMIN only (system.update); the backend enforces it.
        path: 'system/update',
        name: 'system.update',
        component: () => import('@/views/system/SystemUpdateView.vue'),
        meta: { requiresAuth: true, permission: 'system.update' },
      },
      {
        // Own account only (V1 audit A-15); no permission beyond being signed in.
        path: 'account/security',
        name: 'account.security',
        component: () => import('@/views/account/AccountSecurityView.vue'),
        meta: { requiresAuth: true },
      },
    ],
  },
  {
    path: '/:pathMatch(.*)*',
    redirect: () => {
      return { name: 'dashboard' }
    },
  },
]

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
})

router.beforeEach(async (to, from, next) => {
  const authStore = useAuthStore()

  // 1. Initialize auth store if not already bootstrapped
  if (!authStore.initialized) {
    await authStore.bootstrap()
  }

  const isAuthenticated = authStore.isAuthenticated
  const mustChangePassword = authStore.mustChangePassword
  const isMfaPending = authStore.mfaPending

  // 2. Handle authenticated users
  if (isAuthenticated) {
    // If user must change password, restrict access only to change-password route
    if (mustChangePassword && to.name !== 'change-password') {
      return next({ name: 'change-password' })
    }

    // Admin roles must enrol MFA first (backend answers 403 MFA_ENROLLMENT_REQUIRED).
    if (
      authStore.user?.mfa_enrollment_required &&
      !['account.security', 'change-password'].includes(to.name)
    ) {
      return next({ name: 'account.security' })
    }

    // Guest-only or MFA challenge pages should redirect authenticated users
    if (to.meta.guestOnly || to.meta.requiresMfaPending) {
      if (mustChangePassword) {
        return next({ name: 'change-password' })
      }
      return next({ name: 'dashboard' })
    }

    return next()
  }

  // 3. Handle unauthenticated users
  // Route requires active MFA challenge pending
  if (to.meta.requiresMfaPending) {
    if (isMfaPending) {
      return next()
    }
    return next({ name: 'login' })
  }

  // Route requires authentication
  if (to.meta.requiresAuth) {
    authStore.intendedRoute = to.fullPath
    return next({ name: 'login' })
  }

  // Guest-only routes
  return next()
})

export default router
