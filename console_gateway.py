#!/usr/bin/env python3
"""PVE WebSocket relay with one-use tickets and renewable authorization leases."""
from __future__ import annotations
import argparse
import base64
import fcntl
import hashlib
from ipaddress import ip_address
import json
import os
from pathlib import Path
import re
import select
import socket
import socketserver
import ssl
import stat
import threading
import time
from urllib.parse import urlencode, urlparse


def read_record(file, path: Path) -> dict:
    info = os.fstat(file.fileno())
    entry = path.lstat()
    if not stat.S_ISREG(entry.st_mode) or entry.st_ino != info.st_ino or entry.st_dev != info.st_dev or info.st_nlink != 1:
        raise ValueError('Invalid ticket file')
    file.seek(0)
    raw = file.read(32769)
    if len(raw) > 32768:
        raise ValueError('Invalid ticket size')
    record = json.loads(raw)
    if not isinstance(record, dict):
        raise ValueError('Invalid ticket')
    return record


def binding(record: dict) -> tuple:
    return (record.get('scope', 'client'), *(record[key] for key in ('service_id', 'client_id', 'subject', 'session', 'uuid', 'vmid', 'server_id', 'expires')))


class Handler(socketserver.BaseRequestHandler):
    def handle(self):
        handshake = True
        active = False
        claimed = False
        frontend = None
        upstream = None
        file = None
        path = None
        try:
            deadline = time.monotonic() + self.server.header_seconds
            if self.server.tls_context is not None:
                frontend = self.server.tls_context.wrap_socket(self.request, server_side=True, do_handshake_on_connect=False)
                remaining = deadline - time.monotonic()
                if remaining <= 0:
                    return
                frontend.settimeout(remaining)
                frontend.do_handshake()
                self.request = frontend
            raw = b''
            while b'\r\n\r\n' not in raw:
                remaining = deadline - time.monotonic()
                if remaining <= 0:
                    return
                self.request.settimeout(remaining)
                chunk = self.request.recv(4096)
                if not chunk:
                    return
                raw += chunk
                if len(raw) > 8192:
                    return
            header, extra = raw.split(b'\r\n\r\n', 1)
            lines = header.decode('latin1').split('\r\n')
            first = lines[0].split(' ')
            if len(first) != 3 or first[0] != 'GET' or first[2] != 'HTTP/1.1':
                return
            if first[1] == '/health':
                self.request.sendall(b'HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nOK')
                return
            match = re.fullmatch(r'/console/(?:([st][a-f0-9]{64})/)?([a-f0-9]{64})', first[1])
            if not match:
                return
            headers = {}
            for line in lines[1:]:
                key, value = line.split(':', 1)
                key = key.strip().lower()
                if not key or key in headers:
                    return
                headers[key] = value.strip()
            key = headers.get('sec-websocket-key', '')
            if headers.get('upgrade', '').lower() != 'websocket' or 'upgrade' not in [part.strip().lower() for part in headers.get('connection', '').split(',')]:
                return
            if headers.get('sec-websocket-version') != '13' or len(base64.b64decode(key, validate=True)) != 16:
                return
            site, token = match.groups()
            storage = self.server.ticket_directory(site)
            path = storage / (token + '.json')
            descriptor = os.open(path, os.O_RDWR | os.O_NOFOLLOW)
            file = os.fdopen(descriptor, 'r+b', buffering=0)
            info = os.fstat(file.fileno())
            if not stat.S_ISREG(info.st_mode) or info.st_mode & 0o077 or info.st_uid != storage.stat().st_uid:
                return
            fcntl.flock(file, fcntl.LOCK_EX)
            try:
                record = read_record(file, path)
                now = time.time()
                if record.get('expires', 0) <= now or record.get('lease_until', record.get('expires', 0)) <= now:
                    path.unlink(missing_ok=True)
                    return
                if record.get('version') != 2 or record.get('state') != 'issued' or record.get('connect_before', 0) <= now:
                    return
                if headers.get('origin') != record['origin'] or self.server.client_ip(self.client_address[0], headers) != ip_address(record['client_ip']):
                    return
                if not self.server.consoles.acquire(blocking=False):
                    self.request.sendall(b'HTTP/1.1 503 Busy\r\nContent-Length: 0\r\nConnection: close\r\n\r\n')
                    return
                active = True
                record['state'] = 'connected'
                raw_record = json.dumps(record).encode()
                file.seek(0)
                file.truncate()
                file.write(raw_record)
                file.flush()
                expected = binding(record)
                claimed = True
            finally:
                fcntl.flock(file, fcntl.LOCK_UN)
            self.server.handshakes.release()
            handshake = False
            target = urlparse(record['authority'])
            if target.scheme != 'https' or not target.hostname:
                return
            context = ssl.create_default_context(cafile=record['ca_file'] or None)
            upstream = context.wrap_socket(socket.create_connection((target.hostname, target.port or 8006), timeout=10), server_hostname=target.hostname)
            query = urlencode({'port': record['proxy']['port'], 'vncticket': record['proxy']['ticket']})
            request = (f'GET /api2/json{record["path"]}?{query} HTTP/1.1\r\nHost: {target.netloc}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n'
                       f'Sec-WebSocket-Key: {key}\r\nSec-WebSocket-Version: 13\r\nCookie: PVEAuthCookie={record["auth"]["ticket"]}\r\n\r\n')
            upstream.sendall(request.encode('ascii'))
            response = b''
            deadline = time.monotonic() + 10
            while b'\r\n\r\n' not in response:
                remaining = deadline - time.monotonic()
                if remaining <= 0:
                    return
                upstream.settimeout(remaining)
                chunk = upstream.recv(4096)
                if not chunk:
                    return
                response += chunk
                if len(response) > 32768:
                    return
            header, remainder = response.split(b'\r\n\r\n', 1)
            if not header.startswith(b'HTTP/1.1 101'):
                return
            reply_headers = {name.strip().lower(): value.strip() for name, value in (line.split(':', 1) for line in header.decode('latin1').split('\r\n')[1:] if ':' in line)}
            accept = base64.b64encode(hashlib.sha1((key + '258EAFA5-E914-47DA-95CA-C5AB0DC85B11').encode()).digest()).decode()
            if reply_headers.get('sec-websocket-accept') != accept or not self.lease(file, path, expected):
                return
            self.request.settimeout(2)
            upstream.settimeout(2)
            self.request.sendall(f'HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {accept}\r\n\r\n'.encode())
            if remainder:
                if not self.lease(file, path, expected):
                    return
                self.request.sendall(remainder)
            if extra:
                if not self.lease(file, path, expected):
                    return
                upstream.sendall(extra)
            sockets = {sock.fileno(): sock for sock in (self.request, upstream)}
            tls_sockets = (upstream, frontend) if frontend is not None else (upstream,)
            poller = select.poll()
            for descriptor in sockets:
                poller.register(descriptor, select.POLLIN)
            end = time.monotonic() + 3600
            next_check = 0
            while time.monotonic() < end:
                if time.monotonic() >= next_check:
                    if not self.lease(file, path, expected):
                        return
                    next_check = time.monotonic() + 1
                pending = [sock for sock in tls_sockets if sock.pending()]
                ready = [sockets[descriptor] for descriptor, _ in poller.poll(0 if pending else 1000)]
                ready.extend(sock for sock in pending if sock not in ready)
                for source in ready:
                    destination = upstream if source is self.request else self.request
                    chunk = source.recv(65536)
                    if not chunk:
                        return
                    if not self.lease(file, path, expected):
                        return
                    destination.sendall(chunk)
                    if source in tls_sockets:
                        for _ in range(8):
                            if not source.pending():
                                break
                            if not self.lease(file, path, expected):
                                return
                            destination.sendall(source.recv(65536))
        except (OSError, ValueError, KeyError, TypeError, ssl.SSLError):
            # Never log grant tokens, cookies or PVE credentials.
            pass
        finally:
            if frontend is not None:
                frontend.close()
            if upstream:
                upstream.close()
            if file:
                try:
                    if claimed:
                        fcntl.flock(file, fcntl.LOCK_EX)
                        if binding(read_record(file, path)) == expected:
                            path.unlink(missing_ok=True)
                except (OSError, ValueError, KeyError, TypeError):
                    pass
                finally:
                    file.close()
            if active:
                self.server.consoles.release()
            if handshake:
                self.server.handshakes.release()

    @staticmethod
    def lease(file, path, expected):
        fcntl.flock(file, fcntl.LOCK_SH)
        try:
            record = read_record(file, path)
            return record.get('state') == 'connected' and binding(record) == expected and min(record['lease_until'], record['expires']) > time.time()
        finally:
            fcntl.flock(file, fcntl.LOCK_UN)


class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True
    header_seconds = 5

    def __init__(self, address, handler, *, max_connections=64, max_handshakes=32, trusted_proxies=(), tls_context=None):
        self.handshakes = threading.BoundedSemaphore(max_handshakes)
        self.consoles = threading.BoundedSemaphore(max_connections)
        self.request_queue_size = max_connections + max_handshakes
        self.trusted_proxies = frozenset(trusted_proxies)
        self.tls_context = tls_context
        self.storage = None
        super().__init__(address, handler)

    def ticket_directory(self, site):
        if self.storage is not None and site is None:
            return self.storage
        if self.storage is not None or site is None:
            raise ValueError('Ticket storage mode mismatch')
        base = '/dev/shm' if site[0] == 's' else '/tmp'
        path = Path(base) / ('whmpve-console-' + site[1:])
        info = path.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_mode & 0o077 or info.st_uid != os.geteuid():
            raise ValueError('Invalid private console directory')
        return path

    def client_ip(self, peer, headers):
        address = ip_address(peer)
        return ip_address(headers['x-real-ip']) if address in self.trusted_proxies else address

    def process_request(self, request, address):
        if not self.handshakes.acquire(blocking=False):
            self.shutdown_request(request)
            return
        try:
            super().process_request(request, address)
        except BaseException:
            self.handshakes.release()
            raise


def connection_limit(value: str) -> int:
    limit = int(value)
    if not 1 <= limit <= 512:
        raise argparse.ArgumentTypeError('the limit must be between 1 and 512')
    return limit


def server_tls_context(cert, key):
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    context.set_alpn_protocols(['http/1.1'])
    context.load_cert_chain(cert, key, password='')
    return context


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--bind', default='127.0.0.1')
    parser.add_argument('--port', type=int, default=8090)
    parser.add_argument('--storage', help='override automatic ticket storage with an existing private directory')
    parser.add_argument('--max-connections', type=connection_limit, default=64, help='active consoles (default: 64)')
    parser.add_argument('--max-handshakes', type=connection_limit, default=32, help='initial handshakes (default: 32)')
    parser.add_argument('--trusted-proxy', type=ip_address, action='append', default=[], help='proxy IP allowed to set X-Real-IP (repeatable)')
    parser.add_argument('--tls-cert', help='PEM certificate chain for direct WSS')
    parser.add_argument('--tls-key', help='PEM private key for direct WSS')
    args = parser.parse_args()
    if bool(args.tls_cert) != bool(args.tls_key):
        parser.error('--tls-cert and --tls-key must be set together')
    tls_context = None
    if args.tls_cert:
        try:
            tls_context = server_tls_context(args.tls_cert, args.tls_key)
        except (OSError, ValueError) as error:
            parser.error(f'Cannot load the TLS certificate or key: {error}')
    storage = Path(args.storage).resolve() if args.storage else None
    if storage is not None and (not storage.is_dir() or storage.stat().st_mode & 0o077):
        raise SystemExit('Storage must be an existing private directory (0700).')
    with Server((args.bind, args.port), Handler, max_connections=args.max_connections, max_handshakes=args.max_handshakes,
                trusted_proxies=args.trusted_proxy, tls_context=tls_context) as server:
        server.storage = storage
        server.serve_forever(poll_interval=.5)


if __name__ == '__main__':
    main()
