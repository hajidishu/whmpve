# 界面语言

WHMPVE 提供简体中文、英语、法语和德语。客户在 WHMCS 客户区切换语言。管理员在「我的账户」选择后台语言。WHMCS 自带英语和法语后台语言，后台要选简体中文或德语时，先安装对应的 WHMCS 后台语言包。 [WHMCS 语言设置](https://docs.whmcs.com/9-1/system/localization/localization/)

## 添加翻译

1. 在 `modules/servers/whmpve/lang/` 复制 `english.json`，文件名使用 WHMCS 的语言名称，例如 `spanish.json`。
2. 把 `_locale` 改为该语言的地区代码，例如 `es`。翻译词条的值，保留词条键和 `{hostname}` 等占位符。
3. 上传 JSON 文件，在 WHMCS 切换语言并刷新。缺少的词条会使用简体中文。
