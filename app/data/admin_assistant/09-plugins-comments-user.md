# Plugins, comments, members / 插件、评论与会员概览

## 插件（addons）

入口在 **应用 → 本地应用**（`addon/index`），页面分「本机 / 云市场」等 Tab。

1. **安装**：在本机 Tab 上传插件 zip → 系统解压到 `runtime/` 暂存目录、过安全闸门后落到 **仓库根的 `addons/{标识}/`**。插件代码**不在** `app/data/addon/`，那个目录只放文档与云市场素材。  
2. **装完默认是「停用」状态**：安装流程会强制把 `state` 置 0，再单独点 **启用** 才会生效；本地安装也不弹配置向导。  
3. **配置**：启用后在列表点 **设置**，字段由插件自带的 `config.php` 决定（详见 `app/data/addon/docs/addon-config-ui.md`）。  
4. **插件不会往后台菜单树里加节点**：`app/admin/common/auth.php` 是静态清单，所以「装完要在角色里勾选菜单」这件事在 v12 **不存在**。插件自己的后台页面走 `/addons/{标识}/…` 路由，**不经过后台权限校验** —— 是否要求管理员登录由插件代码自己判断，因此**不要**把插件的隐藏地址当成访问控制。  
5. **升级 CMS 内核**前确认插件兼容性；先在测试环境演练。卸载遵循插件作者说明（有的需执行卸载 SQL），避免手删目录遗留钩子。  
6. **开发插件**：见 [`app/data/addon/docs/addon-developer.md`](../addon/docs/addon-developer.md)。  
7. **云市场**：`addon_cloud.status=1` 后走 RS256 签名目录一键安装；`mock=1` 可联调空/本地目录。旧 `api.maccms.com` **只读并存**（丰富本机列表，不装包）。协议：[`addon-marketplace-protocol.md`](../addon/docs/addon-marketplace-protocol.md)。注意 `addon_cloud` 出厂没有默认块，只能手改 `app/extra/maccms.php`。

## 评论 / 留言

- **评论管理**（**基础 → 评论管理**，`comment/data`）：审核、屏蔽、删除。  
- **留言管理**（**基础 → 留言管理**，`gbook/data`）：定期清理垃圾，防止 XSS / 外链垃圾 SEO。  
- **黑名单**：**基础 → 评论黑名单**（`comment/blacklist`）拦用户与关键词，**基础 → IP黑名单**（`comment/blacklist_ip`）拦来源 IP。  
- **违禁词**不在「安全」菜单里（v12 **没有网站安全分组**）：  
  - 内容侧过滤词 → **网站参数 → 网站参数** 的 `app[filter_words]`（[config.html:968-971](file:///d:/wwwroot/maccms/maccms12/app/admin/view/system/config.html#L968-L971)）  
  - 会员昵称/互动过滤词 → **网站参数 → 会员互动** 的 `user[filter_words]`（[configuser.html:325-328](file:///d:/wwwroot/maccms/maccms12/app/admin/view/system/configuser.html#L325-L328)）

## 会员、卡密、订单（若启用）

1. **网站参数 → 会员互动**（`system/configuser`）：注册、积分、试看等开关与规则。  
2. 内容在 **用户** 分组：**管理员 / 会员组 / 会员 / 充值卡 / 积分商城 / 会员订单 / 提现记录 / 优惠券 / 秒杀** 等页。  
3. 支付与微信凭据在 **网站参数 → 对接与账号** 的 Tab 里（对接 / 微信 / 在线支付）；回调 URL 必须 HTTPS 且公网可达。  
4. 测试支付用 **沙箱/小额**；不要将生产密钥贴到对话或截图外传。

## English summary

**Addons**: installed from **应用 → 本地应用** into root `addons/`; every local install lands **disabled** (enable it explicitly), and plugins add **no** admin-menu nodes — their `/addons/...` routes bypass the admin permission check, so a hidden URL is not access control. **Comments/Gbook**: moderate under **基础**, use the blacklist pages plus the `filter_words` fields in 网站参数 / 会员互动 (there is no "security" menu). **Membership/Pay**: configure gateways under **网站参数 → 对接与账号**; never paste live keys into chat logs.
