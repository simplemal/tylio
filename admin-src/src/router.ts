import { createRouter, createWebHistory } from 'vue-router'
import { useAuth } from './stores/auth'
import { api } from './api'

const Login = () => import('./views/Login.vue')
const Dashboard = () => import('./views/Dashboard.vue')
const EditBlock = () => import('./views/EditBlock.vue')
const Theme = () => import('./views/Theme.vue')
const Settings = () => import('./views/Settings.vue')
const Maintenance = () => import('./views/Maintenance.vue')
const Media = () => import('./views/Media.vue')
const Stats = () => import('./views/Stats.vue')
const Submissions = () => import('./views/Submissions.vue')

export const router = createRouter({
  history: createWebHistory('/admin/'),
  scrollBehavior(to, _from, savedPosition) {
    // Hash-link support: when the target URL has `#anchor`, scroll the
    // anchor into view. The async view (Settings, etc.) only mounts
    // after a tick, so we wait one animation frame so the element
    // exists when we query it. Falls back to the top of the page if
    // the anchor isn't found by then.
    if (to.hash) {
      return new Promise((resolve) => {
        setTimeout(() => {
          const el = document.querySelector(to.hash)
          resolve(el ? { el: to.hash, behavior: 'smooth', top: 80 } : { top: 0 })
        }, 50)
      })
    }
    return savedPosition || { top: 0 }
  },
  routes: [
    { path: '/', name: 'dashboard', component: Dashboard, meta: { auth: true } },
    {
      path: '/blocks/:id',
      name: 'edit-block',
      component: EditBlock,
      meta: { auth: true },
      props: true,
    },
    { path: '/theme', name: 'theme', component: Theme, meta: { auth: true } },
    { path: '/settings', name: 'settings', component: Settings, meta: { auth: true } },
    { path: '/maintenance', name: 'maintenance', component: Maintenance, meta: { auth: true } },
    { path: '/media', name: 'media', component: Media, meta: { auth: true } },
    { path: '/stats', name: 'stats', component: Stats, meta: { auth: true } },
    { path: '/submissions', name: 'submissions', component: Submissions, meta: { auth: true } },
    { path: '/login', name: 'login', component: Login },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuth()
  if (!auth.bootstrapped) {
    try {
      const r = await api.me()
      auth.user = r.user
      auth.csrf = r.csrf
    } catch {
      // not logged
    }
    auth.bootstrapped = true
  }
  if (to.meta.auth && !auth.isLogged) {
    return { name: 'login', query: { next: to.fullPath } }
  }
  if (to.name === 'login' && auth.isLogged) {
    return { name: 'dashboard' }
  }
})
