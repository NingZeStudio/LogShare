import { createRouter, createWebHashHistory } from 'vue-router'
import HomeView from '../views/HomeView.vue'
import LogView from '../views/LogView.vue'
import ApiDocsView from '../views/ApiDocsView.vue'
import ImprintView from '../views/ImprintView.vue'
import PrivacyPolicyView from '../views/PrivacyPolicyView.vue'
import { setPageTitle, getCurrentPageTemplate } from '@/lib/pageTitle'

const router = createRouter({
  history: createWebHashHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      name: 'home',
      component: HomeView,
      meta: { title: 'home' }
    },
    {
      path: '/api-docs',
      name: 'api-docs',
      component: ApiDocsView,
      meta: { title: 'apiDocs' }
    },
    {
      path: '/imprint',
      name: 'imprint',
      component: ImprintView,
      meta: { title: 'imprint' }
    },
    {
      path: '/privacy',
      name: 'privacy',
      component: PrivacyPolicyView,
      meta: { title: 'privacy' }
    },
    {
      path: '/:id',
      name: 'log',
      component: LogView,
      meta: { title: 'log' }
    }
  ]
})

// 全局路由守卫：更新页面标题
router.beforeEach((to, _, next) => {
  const template = to.meta.title as string || getCurrentPageTemplate(to.name?.toString());

  if (template === 'log' && to.params.id) {
    // 对于日志页面，我们需要等待组件加载后才能获取到标题
    // 这里设置一个临时标题，实际标题会在LogView组件中更新
    setPageTitle(template, { id: to.params.id as string });
  } else {
    setPageTitle(template);
  }

  next();
});

export default router
