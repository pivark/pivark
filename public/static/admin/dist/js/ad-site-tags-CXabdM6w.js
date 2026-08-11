function e(e){let t=String(e==null?``:e).trim();return t?`{pv:siteads slot="${t}" /}`:``}function t(e,t){let n=String(e==null?``:e).trim();return n?`{pv:siteads slot="${n}"}\n${(t==null?void 0:t.trim())||`<div class="pv-ad-item">
  <a href="{$field.link_url}" target="{$field.target}">
    <img src="{$field.image_url}" alt="{$field.title}">
  </a>
  {pv:if empty="field.title"}{pv:else}<h3>{$field.title}</h3>{/pv:if}
  {pv:if empty="field.subtitle"}{pv:else}<p>{$field.subtitle}</p>{/pv:if}
</div>`}\n{/pv:siteads}`:``}export{t as n,e as t};