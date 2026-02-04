/**
 * 页面标题管理工具
 */

// 定义页面标题模板
export const pageTitleTemplates = {
  home: '首页 - NingZeLogs Next',
  log: (title?: string, id?: string) => `${title || id || '日志'} - NingZeLogs Next`,
  apiDocs: 'API文档 - NingZeLogs Next',
  imprint: '法律声明 - NingZeLogs Next',
  privacy: '隐私政策 - NingZeLogs Next',
  notFound: '页面未找到 - NingZeLogs Next'
};

/**
 * 设置页面标题
 * @param template 模板名称或自定义标题
 * @param params 模板参数
 */
export const setPageTitle = (template: keyof typeof pageTitleTemplates | string, params?: { [key: string]: string }) => {
  let title = '';
  
  if (typeof template === 'string' && template in pageTitleTemplates) {
    const templateFn = pageTitleTemplates[template as keyof typeof pageTitleTemplates];
    if (typeof templateFn === 'function') {
      title = templateFn(params?.title, params?.id);
    } else {
      title = templateFn;
    }
  } else if (typeof template === 'string') {
    title = template;
  } else {
    title = 'NingZeLogs Next';
  }
  
  document.title = title;
};

/**
 * 获取当前页面标题模板
 */
export const getCurrentPageTemplate = (routeName: string | undefined) => {
  switch (routeName) {
    case 'home':
      return 'home';
    case 'log':
      return 'log';
    case 'api-docs':
      return 'apiDocs';
    case 'imprint':
      return 'imprint';
    case 'privacy':
      return 'privacy';
    default:
      return 'notFound';
  }
};