# Alpine 3.24 和 Debian 13 精简模板

六份镜像均预装 Cloud-Init 和 QEMU Guest Agent。各镜像的软件包版本见同目录的 `.packages.txt`，下载后可用 `sha256sum -c SHA256SUMS` 校验。

| 类型 | 架构 | Alpine 3.24 | Debian 13 |
| --- | --- | --- | --- |
| LXC | amd64（x86_64） | `alpine-3.24-cloudinit-qga_amd64.tar.xz` | `debian-13-cloudinit-qga_amd64.tar.zst` |
| LXC | ARM64 | `alpine-3.24-cloudinit-qga_arm64.tar.xz` | `debian-13-cloudinit-qga_arm64.tar.zst` |
| KVM | amd64（x86_64） | `alpine-3.24-cloudinit-qga_kvm-amd64.qcow2` | `debian-13-cloudinit-qga_kvm-amd64.qcow2` |

## LXC

1. 把 Alpine 的 `.tar.xz` 或 Debian 的 `.tar.zst` 上传到 PVE 节点的「local → CT Templates」。
2. 在 WHMPVE 添加 LXC 镜像，模板卷名填写 `local:vztmpl/文件名`。

容器默认由 PVE 设置网络和密码。Cloud-Init 默认停用，可在容器内提供本地 NoCloud 配置后自行启用。QEMU Guest Agent 在容器内保持停用。

## amd64 KVM

镜像已预装并使用bbr+fq。

1. 在 amd64 PVE 节点把 `.qcow2` 导入虚拟机，设为启动盘。Alpine 镜像使用 SeaBIOS。
2. 添加 Cloud-Init 光驱，在虚拟机选项中启用 QEMU Guest Agent，再转换为模板。
3. 在 WHMPVE 添加 KVM 镜像，填写转换后的模板 VMID。 [KVM 镜像配置步骤](../docs/install.md#kvm-虚拟机)
