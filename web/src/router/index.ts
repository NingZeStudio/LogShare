import { createRouter, createWebHashHistory } from 'vue-router'
import HomeView from '../views/HomeView.vue'
import LogView from '../views/LogView.vue'
import ApiDocsView from '../views/ApiDocsView.vue'
import ImprintView from '../views/ImprintView.vue'
import PrivacyPolicyView from '../views/PrivacyPolicyView.vue'

const router = createRouter({
  history: createWebHashHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      name: 'home',
      component: HomeView
    },
    {
      path: '/api-docs',
      name: 'api-docs',
      component: ApiDocsView
    },
    {
      path: '/imprint',
      name: 'imprint',
      component: ImprintView
    },
    {
      path: '/privacy',
      name: 'privacy',
      component: PrivacyPolicyView
    },
    {
      path: '/:id',
      name: 'log',
      component: LogView
    }
  ]
})

export default router
