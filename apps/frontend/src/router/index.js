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
