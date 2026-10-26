# 会员参数、订单、支付、微信、邮件、短信

## 会员参数配置 (`网站参数 → 会员互动` / `menu/configuser`)

见 `maccms.user`：`status` 总开关、`reg_*` 注册与审核、`login_verify`、**积分/邀请/奖励/提现**、**试看**、**视频/文章收费 points_type**、头像等。  
与 **用户 → 会员组 / 会员 / 充值卡 / 订单 / 提现** 联动。

### 运营步骤（概念）

1. 先定 **会员组**（免费/VIP/包月）与 **权限、价格、积分倍率**（以表单为准）。  
2. 配置 **支付**（见下）确保 **回调成功**。  
3. 小金额 **真实支付测试** 再走正式。

---

## 在线支付配置（**网站参数 → 对接与账号** 的第三个页签「在线支付」；`menu/configpay`，旧地址 `system/configpay` 302 到 `configconnect?tab=pay`）

- 页签里直接可编辑的是 **`pay[min]`（最低充值）、`pay[scale]`、`pay[card][url]`（卡密接口）**；网关参数由 `mac_extends_list('pay')` 渲染的驱动片段提供（[System.php:1013](file:///d:/wwwroot/maccms/maccms12/app/admin/controller/System.php#L1013) → `app/admin/view/extend/pay/{alipay,weixin,codepay,zhapay,jeepay,epay}.html`），存进 **`maccms.pay.{网关}`**（`appid` / `appkey` / `mchid` 等，见 [app/extra/maccms.php:285-315](file:///d:/wwwroot/maccms/maccms12/app/extra/maccms.php#L285-L315)）。  
- **异步通知必须为公网 HTTPS**（微信支付强制）。  
- **沙箱密钥不得用于生产**；退款、对账在各支付平台商户后台完成。

---

## 微信对接配置（**网站参数 → 对接与账号** 的第二个页签；`menu/configweixin`，旧地址 302 到 `configconnect?tab=weixin`）

- 表单字段只有 **`weixin.status`、`weixin.token`** 与关注 / 搜索 / 播放 / 对接 / 无资源几类自动回复文案（[config_embed_weixin.html](file:///d:/wwwroot/maccms/maccms12/app/admin/view/system/config_embed_weixin.html)）：**没有 AppID / AppSecret / AESKey 输入框**，支付用的 `pay.weixin.appid|mchid|appkey` 在上一节的支付页签里。  
- **回调域名** 必须备案且 **白名单授权**。

---

## 邮件、短信（**网站参数 → 邮件与短信** `system/configemail`；**网站参数 → 短信发送配置** `system/configsms`）

- **邮件**：SMTP 或 API，填发件邮箱、端口、加密方式、授权码（非邮箱登录密码若为厂商规定）。测试发信到自用邮箱。  
- **短信**：驱动只有 **阿里云** 与 **腾讯云** 两种（`app/admin/view/extend/sms/{aliyun,qcloud}.html` + `app/common/extend/sms/`）；填 AccessKey、签名、模板 ID。注意 **频次与费用**，防刷子盗刷短信。**没有「短信宝」驱动**，旧教程的字段在本版本不存在。

---

## 会员订单、充值卡、提现 (`order` / `card` / `cash`)

- **订单**：查支付状态、补单、异常关闭。  
- **充值卡**：批量生成卡密、渠道发放、防泄露。  
- **提现**：审核、打款、拒绝原因记录。

---

## English summary

**User module** is driven by `maccms.user` + **group/order/pay**. **Pay & WeChat** need correct **callback URLs** and production keys. **Email/SMS** need provider credentials and rate limits.
