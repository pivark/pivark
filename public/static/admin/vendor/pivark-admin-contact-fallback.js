/* pivark-admin-contact-fallback.js — 断网/CSP 拖底（Vue 轨优先；本文件供脚本挂载备选） */
window.PIVARK_ADMIN_CONTACT_FALLBACK = {
  tabs: [
    { id: 'site', label: '官网', panelId: 'site', color: '#1e9fff' },
    { id: 'docs', label: '文档', panelId: 'docs', color: '#0f766e' },
    { id: 'consult', label: '咨询', panelId: 'consult', color: '#7c3aed' },
    { id: 'log', label: '日志', panelId: 'log', color: '#b45309' }
  ],
  panels: [
    { id: 'site', title: 'PivArk 官网', body: ['产品介绍、版本对比与商业授权入口'], ctaLabel: '打开官网', ctaHref: 'https://pivark.com' },
    { id: 'docs', title: '使用文档', body: ['安装、升级与插件开发文档'], ctaLabel: '打开文档', ctaHref: 'https://pivark.com/docs/' },
    { id: 'consult', title: '技术服务咨询', body: ['安装部署与升级排查', '定制与商业授权咨询'], note: '技术服务为有偿服务。', ctaLabel: '联系官网', ctaHref: 'https://pivark.com/contact' },
    { id: 'log', title: '更新日志', body: ['查看近期版本变更'], ctaLabel: '查看 CHANGELOG', ctaHref: 'https://pivark.com/docs/CHANGELOG.md' }
  ]
};
