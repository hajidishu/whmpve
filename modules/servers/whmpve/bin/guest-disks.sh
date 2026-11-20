#!/bin/sh
# Fixed Guest Agent payload. All instance-specific values arrive as arguments.
set -eu
export LC_ALL=C
serial=$1
gib=$2
mountpoint=$3
mode=$4
expected_uuid=$5
owner=$6
fail() { echo "$*" >&2; exit 1; }
for tool in lsblk blkid blockdev sfdisk partx mkfs.ext4 findmnt mount wipefs flock readlink; do
    command -v "$tool" >/dev/null 2>&1 || fail "Missing tool: $tool"
done
case "$serial:$owner" in *[!a-zA-Z0-9:-]*) fail 'Invalid disk identity';; esac
bytes=$(awk -v gib="$gib" 'BEGIN { if (gib !~ /^[0-9]+([.][0-9]+)?$/ || gib<=0 || gib>65536) exit 1; printf "%.0f", gib*1073741824 }') || fail 'Invalid disk capacity'
case "$mode" in create|restore|grow) ;; *) fail 'Invalid operation';; esac
case "$mountpoint" in /*) ;; *) fail 'Invalid mountpoint';; esac
case "$mountpoint" in *[!a-zA-Z0-9_./-]*|*/../*|*/./*|*/) fail 'Invalid mountpoint';; esac
case "$mountpoint" in /|/etc|/etc/*|/boot|/boot/*|/dev|/dev/*|/proc|/proc/*|/sys|/sys/*|/run|/run/*|/usr|/usr/*|/var|/var/lib|/var/lib/whmpve|/var/lib/whmpve/*) fail 'Reserved mountpoint';; esac
if command -v cloud-init >/dev/null 2>&1; then
    attempts=0
    while :; do
        status=$(cloud-init status 2>&1) || fail "Cloud-Init not ready: $status"
        case "$status" in *'status: done'*) break;; *'status: running'*|*'status: not run'*) ;; *) fail "Cloud-Init not ready: $status";; esac
        attempts=$((attempts+1)); [ "$attempts" -lt 120 ] || fail 'Cloud-Init timeout'
        sleep 1
    done
fi
device=$(lsblk -dnpo NAME,SERIAL | awk -v serial="$serial" '$2==serial {print $1}')
[ -n "$device" ] && [ "$(printf '%s\n' "$device" | wc -l)" -eq 1 ] || fail 'Disk serial not found or ambiguous'
[ -b "$device" ] || fail 'Disk is not a block device'
[ "$(blockdev --getsize64 "$device")" -eq "$bytes" ] || fail 'Disk capacity mismatch'
root=$(findmnt -nro SOURCE /)
root=$(readlink -f "$root")
[ -b "$root" ] || fail 'Cannot identify root disk'
if lsblk -snpo NAME "$root" | grep -Fx "$device" >/dev/null; then fail 'Refusing system disk'; fi
for path in /var/lib/whmpve /var/lib/whmpve/disks; do
    [ ! -L "$path" ] || fail 'Unsafe state directory'
    mkdir -p "$path"; chmod 700 "$path"
done
state=/var/lib/whmpve/disks/$serial
[ ! -L "$state.lock" ] && [ ! -L "$state.intent" ] && [ ! -L "$state.uuid" ] || fail 'Unsafe disk state'
umask 077
exec 8>/var/lib/whmpve/disks/init.lock
flock -x 8
exec 9>"$state.lock"
flock -x 9
if [ "$mode" = create ]; then
    if [ ! -f "$state.intent" ]; then
        [ -z "$(wipefs --no-act --noheadings -o OFFSET "$device")" ] || fail 'New disk already contains a signature'
        [ "$(lsblk -nrpo NAME "$device" | wc -l)" -eq 1 ] || fail 'New disk already contains partitions'
        cat /proc/sys/kernel/random/uuid >"$state.uuid"
        printf '%s:%s\n' "$owner" "$gib" >"$state.intent"
        sync
    fi
    [ "$(cat "$state.intent")" = "$owner:$gib" ] || fail 'Disk initialization identity mismatch'
    expected_uuid=$(cat "$state.uuid")
    table=$(blkid -p -s PTTYPE -o value "$device" || true)
    if [ -z "$table" ]; then
        [ -z "$(wipefs --no-act --noheadings -o OFFSET "$device")" ] || fail 'Unexpected disk signature'
        printf 'label: gpt\n, , L\n' | sfdisk "$device" >/dev/null
        blockdev --rereadpt "$device"
    else
        [ "$table" = gpt ] || fail 'Unexpected partition table'
    fi
    command -v udevadm >/dev/null 2>&1 && udevadm settle || true
    attempts=0
    while :; do
        partition=$(lsblk -nrpo NAME,TYPE "$device" | awk '$2=="part" {print $1}')
        [ -n "$partition" ] && break
        attempts=$((attempts+1)); [ "$attempts" -lt 20 ] || fail 'Partition not available'
        sleep 1
    done
    [ "$(printf '%s\n' "$partition" | wc -l)" -eq 1 ] || fail 'Unexpected partition layout'
    actual_uuid=$(blkid -s UUID -o value "$partition" || true)
    if [ -z "$actual_uuid" ]; then
        [ -z "$(wipefs --no-act --noheadings -o OFFSET "$partition")" ] || fail 'Unexpected partition signature'
        mkfs.ext4 -q -U "$expected_uuid" -E nodiscard,lazy_itable_init=1,lazy_journal_init=1 "$partition"
        sync
    else
        [ "$actual_uuid" = "$expected_uuid" ] || fail 'Filesystem identity mismatch'
    fi
else
    [ "$expected_uuid" != '-' ] || fail 'Missing preserved filesystem UUID'
    partition=$(lsblk -nrpo NAME,UUID "$device" | awk -v uuid="$expected_uuid" '$2==uuid {print $1}')
    [ -n "$partition" ] && [ "$(printf '%s\n' "$partition" | wc -l)" -eq 1 ] || fail 'Preserved filesystem not found'
fi
[ "$(blkid -s UUID -o value "$partition")" = "$expected_uuid" ] || fail 'Filesystem UUID mismatch'
[ "$(blkid -s TYPE -o value "$partition")" = ext4 ] || fail 'Unexpected filesystem type'
if [ "$mode" = grow ]; then
    command -v resize2fs >/dev/null 2>&1 || fail 'Missing tool: resize2fs'
    [ "$(lsblk -nrpo TYPE "$device" | grep -c '^part$')" -eq 1 ] || fail 'Unexpected partition layout'
    printf ', +\n' | sfdisk --force --no-reread --no-tell-kernel -N 1 "$device" >/dev/null
    partx --update "$device"
    resize2fs "$partition"
fi
path=$mountpoint
while [ "$path" != / ]; do
    [ ! -L "$path" ] || fail 'Mountpoint contains a symlink'
    path=${path%/*}; [ -n "$path" ] || path=/
done
mkdir -p "$mountpoint"
mounted=$(findmnt -rn -M "$mountpoint" -o UUID || true)
if [ -n "$mounted" ]; then
    [ "$mounted" = "$expected_uuid" ] || fail 'Mountpoint is already in use'
else
    [ -z "$(ls -A "$mountpoint")" ] || fail 'Mountpoint directory is not empty'
fi
[ ! -L /etc/fstab ] && [ -f /etc/fstab ] || fail 'Unsafe fstab'
existing=$(awk -v target="$mountpoint" '$1 !~ /^#/ && $2==target {print $1}' /etc/fstab)
if [ -n "$existing" ]; then
    [ "$existing" = "UUID=$expected_uuid" ] || fail 'Mountpoint conflicts with fstab'
else
    printf 'UUID=%s %s ext4 defaults,nofail 0 2 # WHMPVE:%s\n' "$expected_uuid" "$mountpoint" "$serial" >>/etc/fstab
fi
if [ -z "$mounted" ]; then mount "$mountpoint"; fi
[ "$(findmnt -rn -M "$mountpoint" -o UUID)" = "$expected_uuid" ] || fail 'Mount verification failed'
printf 'WHMPVE_DISK:%s\n' "$expected_uuid"
