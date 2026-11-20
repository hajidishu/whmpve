# Apache2 网页控制台中继部署指南

以下命令以 WHMCS 所在的 Debian 或 Ubuntu 主机为例。使用 Apache 2.4.47 或更新版本，先确认 WHMCS 域名可以通过 HTTPS 访问。将 `billing.example.com` 换成 WHMCS 域名，将 `www-data` 换成 WHMCS 站点的 PHP 运行用户。

## 1. 启动本机中继

确认主机已安装 `python3`。下载 WHMPVE 仓库根目录的 `console_gateway.py`，保存到网站根目录之外的 `/opt/whmpve/console_gateway.py`，然后创建 `/etc/systemd/system/whmpve-console.service`。

```ini
[Unit]
Description=WHMPVE Console Gateway
After=network.target

[Service]
User=www-data
ExecStart=/usr/bin/python3 /opt/whmpve/console_gateway.py --bind 127.0.0.1 --port 8090 --trusted-proxy 127.0.0.1
Restart=on-failure
RestartSec=3

[Install]
WantedBy=multi-user.target
```

```sh
sudo systemctl daemon-reload
sudo systemctl enable --now whmpve-console.service
curl -fsS http://127.0.0.1:8090/health
```

确认返回 `OK`。

## 2. 配置 HTTPS 站点

在 WHMCS 域名对应的 HTTPS `<VirtualHost>` 内加入下面的配置。已有全站 `ProxyPass` 时，把 `/console/` 放在它前面。 [Apache WebSocket 文档](https://httpd.apache.org/docs/2.4/mod/mod_proxy.html#protocolupgrade)

```apache
ProxyRequests Off
ProxyPass "/console/" "http://127.0.0.1:8090/console/" upgrade=websocket timeout=3600
SetEnvIf Request_URI "^/console/" whmpve_console

<Location "/console/">
    RequestHeader set X-Real-IP "expr=%{REMOTE_ADDR}"
</Location>
```

给记录该站点请求的 `CustomLog` 加上 `env=!whmpve_console`，避免记录控制台票据。 [Apache 条件日志文档](https://httpd.apache.org/docs/2.4/mod/mod_log_config.html#customlog)

```sh
sudo a2enmod proxy proxy_http headers setenvif
sudo apache2ctl configtest && sudo systemctl reload apache2
```

站点前方有 CDN 或其他代理时，先让 Apache 和 PHP 使用相同的客户源 IP。

## 3. 在 WHMPVE 启用

1. 在 WHMCS「系统设置 → 附加模块 → WHMPVE → 配置」将「控制台目录（可选）」留空。
2. 在「附加模块 → WHMPVE → 服务器 → 配置 → 网页控制台」填写 `wss://billing.example.com/console`。
3. 填写拥有对应实例 `VM.Console` 权限的 PVE 用户和密码，可使用安装教程中创建的 PVE 用户。使用 PVE 默认签发的证书时，确认 PHP 运行用户能读取服务器设置里的 CA 文件。
4. 保存后，用客户账号打开一台运行中的 VPS，在「打开控制台」中选 noVNC 或 xterm.js。
