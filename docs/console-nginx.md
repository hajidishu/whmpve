# 通过 Nginx 为 Python 网页控制台提供 HTTPS

在 WHMCS 所在的 Linux 主机执行。Nginx 使用 WHMCS 站点的 HTTPS 证书接收 WSS 连接，再转发给本机的 Python 中继。先确认 WHMCS 域名可以通过 HTTPS 访问。将 `billing.example.com` 换成 WHMCS 域名，将 `www-data` 换成站点的 PHP 运行用户。

## 1. 启动本机 Python 中继

确认主机已安装 `python3`。下载 [console_gateway.py](https://github.com/hajidishu/whmpve/blob/main/console_gateway.py)，保存到网站根目录之外的 `/opt/whmpve/console_gateway.py`。创建或更新 `/etc/systemd/system/whmpve-console.service`，让脚本通过 HTTP 监听 `127.0.0.1:8090`。

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
sudo systemctl enable whmpve-console.service
sudo systemctl restart whmpve-console.service
curl -fsS http://127.0.0.1:8090/health
```

确认返回 `OK`。

## 2. 在 Nginx 的 HTTPS 站点转发 WSS

在 WHMCS 域名对应的 HTTPS `server` 块中加入下面的配置。aaPanel 用户可打开该网站的「配置文件」编辑。 [Nginx WebSocket 文档](https://nginx.org/en/docs/http/websocket.html)

```nginx
location ^~ /console/ {
    proxy_pass http://127.0.0.1:8090;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header X-Real-IP $remote_addr;
    proxy_read_timeout 1h;
    access_log off;
}
```

```sh
sudo nginx -t && sudo systemctl reload nginx
```

aaPanel 可从面板重载，或执行 `sudo /www/server/nginx/sbin/nginx -t && sudo /etc/init.d/nginx reload`。站点前方有 CDN 或其他代理时，先让 Nginx 和 PHP 使用相同的客户源 IP。

## 3. 在 WHMPVE 启用

1. 在 WHMCS「系统设置 → 附加模块 → WHMPVE → 配置」将「控制台目录（可选）」留空。
2. 在「附加模块 → WHMPVE → 服务器 → 配置 → 网页控制台」填写 `wss://billing.example.com/console`。
3. 填写拥有对应实例 `VM.Console` 权限的 PVE 用户和密码，可使用安装教程中创建的 PVE 用户。使用 PVE 默认签发的证书时，确认 PHP 运行用户能读取服务器设置里的 CA 文件。
4. 保存后，用客户账号打开一台运行中的 VPS，在「打开控制台」中选 noVNC 或 xterm.js。
