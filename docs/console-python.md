# Python 网页控制台中继部署指南

以下操作在 WHMCS 所在的 Linux 主机执行。将 `billing.example.com` 换成客户能访问的控制台域名，将 `www-data` 换成 WHMCS 站点的 PHP 运行用户。

## 1. 放置脚本和证书

1. 确认主机已安装 `python3`。下载 WHMPVE 仓库根目录的 `console_gateway.py`，保存到网站根目录之外的 `/opt/whmpve/console_gateway.py`。
2. 准备浏览器信任的域名证书。将 PEM 格式的证书链和无口令私钥分别放在 `/etc/whmpve/tls/fullchain.pem` 和 `/etc/whmpve/tls/privkey.pem`。让 PHP 运行用户能读取脚本、证书链和私钥，私钥权限设为 `0600`。
3. 在防火墙和安全组放行客户访问的 TCP `8443`。

## 2. 启动服务

创建 `/etc/systemd/system/whmpve-console.service`。

```ini
[Unit]
Description=WHMPVE Console Gateway
After=network.target

[Service]
User=www-data
ExecStart=/usr/bin/python3 /opt/whmpve/console_gateway.py --bind 0.0.0.0 --port 8443 --tls-cert /etc/whmpve/tls/fullchain.pem --tls-key /etc/whmpve/tls/privkey.pem
Restart=on-failure
RestartSec=3

[Install]
WantedBy=multi-user.target
```

```sh
sudo systemctl daemon-reload
sudo systemctl enable --now whmpve-console.service
curl -fsS https://billing.example.com:8443/health
```

确认返回 `OK`。证书续期后执行 `sudo systemctl restart whmpve-console.service`。

## 3. 在 WHMPVE 启用

1. 在 WHMCS「系统设置 → 附加模块 → WHMPVE → 配置」将「控制台目录（可选）」留空。
2. 在「附加模块 → WHMPVE → 服务器 → 配置 → 网页控制台」填写 `wss://billing.example.com:8443/console`。
3. 填写拥有对应实例 `VM.Console` 权限的 PVE 用户和密码，可使用安装教程中创建的 PVE 用户。使用 PVE 默认签发的证书时，确认 PHP 运行用户能读取服务器设置里的 CA 文件。
4. 保存后，用客户账号打开一台运行中的 VPS，在「打开控制台」中选 noVNC 或 xterm.js。
