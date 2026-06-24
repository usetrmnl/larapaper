// docmd.config.js
export default defineConfig({
  title: 'LaraPaper',
  url: 'https://usetrmnl.github.io/larapaper',

  logo: {
    light: 'assets/images/logo-light.svg',
    dark: 'assets/images/logo-dark.svg',
    alt: 'LaraPaper',
    href: '/larapaper/'
  },
  favicon: 'assets/favicon.ico',

  src: 'docs',
  out: 'site',

  layout: {
    spa: true,
    header: {
      enabled: true,
    },
    sidebar: {
      collapsible: true,
      defaultCollapsed: false,
    },
    optionsMenu: {
      position: 'sidebar-top',
      components: {
        search: true,
        themeSwitch: true,
        sponsor: "https://buymeacoffee.com/bnussbau",
      },
    },
    footer: {
      style: 'minimal',
      content:
        '© ' +
        new Date().getFullYear() +
        ' LaraPaper · <a href="https://github.com/usetrmnl/larapaper/blob/main/LICENSE.md">MIT</a> · <a href="/support">Support</a>',
      branding: true,
    },
  },

  theme: {
    name: 'default',
    appearance: 'system',
    codeHighlight: true,
    customCss: ['/assets/css/brand.css'],
  },

  minify: true,
  autoTitleFromH1: true,
  copyCode: true,
  pageNavigation: true,

  redirects: {
    '/posts/one-year-larapaper.html': 'https://usetrmnl.github.io/larapaper/posts/one-year-larapaper/',
  },

  navigation: [
    { title: 'Introduction', path: '/', icon: 'home' },
    {
      title: 'Getting Started',
      icon: 'rocket',
      collapsible: true,
      children: [
        { title: 'Hosting', path: '/getting-started/installation', icon: 'download' },
        { title: 'Requirements (Bare-Metal)', path: '/getting-started/requirements', icon: 'list-checks' },
        {
          title: 'Environment variables',
          path: '/getting-started/environment-variables',
          icon: 'settings',
        },
        {
          title: 'Connecting devices',
          path: '/getting-started/connecting-devices',
          icon: 'monitor-smartphone',
        },
      ],
    },
    {
      title: 'Usage',
      icon: 'play',
      collapsible: true,
      children: [
        { title: 'Generating screens', path: '/usage/generating-screens', icon: 'image' },
        { title: 'Cloud proxy', path: '/usage/cloud-proxy', icon: 'cloud' },
        { title: 'Demo plugins', path: '/usage/demo-plugins', icon: 'puzzle' },
      ],
    },
    {
      title: 'Development',
      icon: 'code',
      collapsible: true,
      children: [
        { title: 'Local setup', path: '/development/local-setup', icon: 'terminal' },
        { title: 'Docker', path: '/development/docker', icon: 'container' },
        { title: 'Devcontainer', path: '/development/devcontainer', icon: 'box' },
      ],
    },
    {
      title: 'Guides',
      icon: 'book-open',
      collapsible: true,
      children: [
        { title: 'Native plugins', path: '/guides/native-plugins', icon: 'plug' },
      ],
    },
    {
      title: 'Blog',
      icon: 'newspaper',
      collapsible: true,
      children: [
        {
          title: 'One Year of LaraPaper',
          path: '/posts/one-year-larapaper',
          icon: 'calendar',
        },
      ],
    },
    { title: 'Support', path: '/support', icon: 'heart' },
    {
      title: 'GitHub',
      path: 'https://github.com/usetrmnl/larapaper',
      icon: 'github',
      external: true,
    },
  ],

  plugins: {
    seo: {
      defaultDescription:
        'LaraPaper is a self-hostable Bring Your Own Server (BYOS) implementation for TRMNL e-paper devices, built with Laravel.',
      openGraph: {
        defaultImage: '/assets/images/one-year-larapaper.jpg',
      },
      twitter: { cardType: 'summary_large_image' },
    },
    sitemap: { defaultChangefreq: 'weekly' },
    search: {},
    mermaid: {},
    llms: {},
  },

  editLink: {
    enabled: true,
    baseUrl: 'https://github.com/usetrmnl/larapaper/edit/main/docs/docs',
    text: 'Edit this page',
  },
});
