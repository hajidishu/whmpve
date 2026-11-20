# Project rules

- Keep the plugin small and explicit. Use straightforward functions and avoid speculative abstractions or duplicate configuration layers.
- Each server owns its images, Plans, IP addresses, settings and tasks. Validate ownership on every reference; never share mutable configuration between servers.
- Each server may own multiple IPv4/IPv6 pools, with independent network settings. IP addresses are globally unique and allocation is transactional. IPv6 accepts a prefix and generates random or sequential host addresses.
- Plans select pools, numeric IPv4/IPv6 counts and optional image groups. Ungrouped images are available to every Plan; group metadata stays administrator-only.
- Bandwidth and monthly traffic use zero for unlimited. Monthly quota supports suspension or a configured speed, default 256 Kbps. Preserve usage across power operations and reinstall; never automatically undo a manual/billing suspension.
- Customer reinstall quotas are monthly, exclude failed tasks and do not restrict administrator operations. LXC Swap is configurable beside memory.
- Product module settings select a server and a Plan belonging to it. Plans are editable records; existing services retain their purchased resource snapshot.
- Plans explicitly select LXC or KVM. Default/reinstall images must match that type. Once a Plan has orders its type is fixed. Keep image sources as LXC template volumes or PVE KVM template IDs; do not add a PVE picker button. Put template filename matching and storage prefix guidance below the template source field, never below the display name.
- Do not expose CPU architecture settings or enforce architecture choices in the UI. Let PVE and its templates determine the necessary runtime parameters.
- Admin navigation consists of servers, instances and logs/tasks. There is no separate operation-audit feature.
- UI help should say what to enter or do. Omit implementation descriptions, generic reassurance, verbose empty states and redundant confirmations. Retain concise warnings for destructive actions.
- Allow Plans to be created and edited without images. Keep image choices empty when none match; require a default image only when resolving a Plan for provisioning. Avoid imposing configuration order through UI restrictions.
- Disk sizes and image minimum disks accept fractional GiB, rounded up to whole MiB. Configurable disk options must preserve fractions. Failed submissions show errors inside the active form dialog and keep its DOM and entered values intact.
- Instance links must use the current WHMCS service owner and open the exact service, not just the client summary.
- Keep new verification in tests/acceptance.py. Use real WHMCS orders/payments and real lightweight Alpine LXC lifecycle tests in the authorized lab. Preserve existing host services and networking.
- Do not remove backend authentication, CSRF checks, ownership checks or resource identity validation while simplifying the UI.
- Console tickets use bounded issuance and connection quotas, private locked files, one-use connection claims and short renewable leases bound to the authenticated session and instance. Keep cleanup, revocation checks and absolute handshake deadlines; never expose PVE login cookies to browsers.
- Plans own explicit system/data storage names and stable data disk IDs. Freeze mountpoint overrides at purchase. Track exact runtime volumes; preserve data volumes through reinstall using PVE reassignment, and never format preserved or unregistered disks. Guest Agent receives fixed scripts with structured arguments; no customer-supplied commands.
