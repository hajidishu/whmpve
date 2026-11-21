# WHMPVE

WHMPVE是一个开源的whmcs对接pve自动化售卖lxc容器和kvm虚拟机的方案

之所以要开发WHMPVE是因为我发现竟然都2026年了，仍然没有一个免费且功能完善的whmcs对接pve方案，在以前你要么使用开源的半成品方案，要么付费使用模块花园的商业解决方案



## 安装与使用

- [插件完整部署教程（含配图）](docs/install.md)
- [LXC 和 KVM 镜像下载](https://github.com/hajidishu/whmpve/tree/main/templates)

注：某些功能在本模块管理页面和whmcs原生页面都有提供（例如服务器对接），但是请务必在插件页面使用这些功能，因为whmcs原生提供的这些功能并不完整，我们并不想把功能拆散到一大堆页面中

### 网页控制台（任选一种）

- [Python 网页控制台中继部署指南](docs/console-python.md)
- [Nginx 网页控制台中继部署指南](docs/console-nginx.md)
- [Apache2 网页控制台中继部署指南](docs/console-apache2.md)

## 许可证

本项目采用 [GNU General Public License v3.0（GPL-3.0）](https://www.gnu.org/licenses/gpl-3.0.html)。
