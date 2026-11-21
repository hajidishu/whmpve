# Alpine 3.24 和 Debian 13 精简模板

六份镜像均预装 Cloud-Init、QEMU Guest Agent 和 OpenSSH。实例开通后，可以使用开通时设置的 root 密码登录 SSH。软件包版本见同目录的 `.packages.txt`。

点击表格里的文件名，在 GitHub 文件页下载镜像。使用 Git 时，安装 Git LFS 后运行 `git lfs pull` 获取两份 QCOW2 镜像。GitHub 的「Download ZIP」默认只包含 QCOW2 指针。

将镜像和 [SHA256SUMS](./SHA256SUMS) 下载到同一目录后，运行 `sha256sum -c --ignore-missing SHA256SUMS` 校验已下载的镜像。

| 类型 | 架构 | Alpine 3.24 | Debian 13 |
| --- | --- | --- | --- |
| LXC | amd64（x86_64） | [alpine-3.24-cloudinit-qga_amd64.tar.xz](./alpine-3.24-cloudinit-qga_amd64.tar.xz) | [debian-13-cloudinit-qga_amd64.tar.zst](./debian-13-cloudinit-qga_amd64.tar.zst) |
| LXC | ARM64 | [alpine-3.24-cloudinit-qga_arm64.tar.xz](./alpine-3.24-cloudinit-qga_arm64.tar.xz) | [debian-13-cloudinit-qga_arm64.tar.zst](./debian-13-cloudinit-qga_arm64.tar.zst) |
| KVM | amd64（x86_64） | [alpine-3.24-cloudinit-qga_kvm-amd64.qcow2](./alpine-3.24-cloudinit-qga_kvm-amd64.qcow2) | [debian-13-cloudinit-qga_kvm-amd64.qcow2](./debian-13-cloudinit-qga_kvm-amd64.qcow2) |

## LXC

1. 把 Alpine 的 `.tar.xz` 或 Debian 的 `.tar.zst` 上传到 PVE 节点的「local → CT Templates」。
2. 在 WHMPVE 添加 LXC 镜像，模板卷名填写 `local:vztmpl/文件名`。

容器默认由 PVE 设置网络和密码。Cloud-Init 默认停用，可在容器内提供本地 NoCloud 配置后自行启用。QEMU Guest Agent 在容器内保持停用。

Debian LXC 预装 `ping`、`curl`、`wget`、`ifconfig`、`vim`、`less`、`sudo` 和 `cron`，`cron` 随系统启动。

## amd64 KVM

镜像已预装并使用bbr+fq。

1. 在 amd64 PVE 节点把 `.qcow2` 导入虚拟机，设为启动盘。Alpine 镜像使用 SeaBIOS。
2. 添加 Cloud-Init 光驱，在虚拟机选项中启用 QEMU Guest Agent，再转换为模板。
3. 在 WHMPVE 添加 KVM 镜像，填写转换后的模板 VMID。 [KVM 镜像配置步骤](../docs/install.md#kvm-虚拟机)
