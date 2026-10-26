# 插件（Addon）资料

> **这个目录只有文档和云市场素材，插件代码在仓库根的 [`addons/`](file:///D:/wwwroot/maccms/maccms12/addons)**（PSR-4 `addons\` → `addons`，见 `composer.json:33`）。
> 插件系统的实现也不在这里，在 [`app/common/addon/`](file:///D:/wwwroot/maccms/maccms12/app/common/addon)。

| 类型 | 路径 |
|------|------|
| 开发文档（已落地） | [`docs/addon-developer.md`](docs/addon-developer.md) |
| 配置界面说明 | [`docs/addon-config-ui.md`](docs/addon-config-ui.md) |
| 云市场协议（客户端） | [`docs/addon-marketplace-protocol.md`](docs/addon-marketplace-protocol.md) |
| 本地包签名说明 | [`docs/local-package-signing.md`](docs/local-package-signing.md) |
| 目录签发工具 | [`../addon_market_cloud/README.md`](../addon_market_cloud/README.md) |
| 市场 backlog（云端运营仍长期） | [`backlog/addon-marketplace.md`](backlog/addon-marketplace.md) |

四篇 `docs/` 于 2026-10-27 逐句对着当前代码树重核过，引用一律是 `文件:行`。
站长视角的菜单、启停与排障看 [说明文档/10-插件与扩展手册.md](file:///D:/wwwroot/maccms/maccms12/说明文档/10-插件与扩展手册.md)，安全边界看 [09-安全与权限手册.md](file:///D:/wwwroot/maccms/maccms12/说明文档/09-安全与权限手册.md)。
