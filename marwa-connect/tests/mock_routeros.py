"""Simulateur de l'API RouterOS (protocole binaire) — OUTIL DE TEST UNIQUEMENT.
Usage : python3 tests/mock_routeros.py 18728  (identifiants : marwa / Secret123).
Ne jamais l'utiliser en production : les données renvoyées sont fictives."""
import socket, threading, struct, sys, time
PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 18728
state = {'users': {}, 'profiles': {'default': {'.id': '*1', 'name': 'default', 'shared-users': '1'}}, 'active': {}, 'seq': 100}
lock = threading.Lock()

def enc_len(n):
    if n < 0x80: return bytes([n])
    if n < 0x4000: return struct.pack('>H', n | 0x8000)
    if n < 0x200000: return struct.pack('>I', n | 0xC00000)[1:]
    if n < 0x10000000: return struct.pack('>I', n | 0xE0000000)
    return b'\xf0' + struct.pack('>I', n)

def recv_exact(c, n):
    b = b''
    while len(b) < n:
        x = c.recv(n - len(b))
        if not x: raise EOFError
        b += x
    return b

def read_len(c):
    b = recv_exact(c, 1)[0]
    if b & 0x80 == 0: return b
    if b & 0xC0 == 0x80: return ((b & 0x3F) << 8) + recv_exact(c, 1)[0]
    if b & 0xE0 == 0xC0: return ((b & 0x1F) << 16) + struct.unpack('>H', recv_exact(c, 2))[0]
    if b & 0xF0 == 0xE0: r = recv_exact(c, 3); return ((b & 0x0F) << 24) + (r[0] << 16) + (r[1] << 8) + r[2]
    return struct.unpack('>I', recv_exact(c, 4))[0]

def read_sentence(c):
    words = []
    while True:
        n = read_len(c)
        if n == 0: return words
        words.append(recv_exact(c, n).decode())

def send(c, words):
    c.sendall(b''.join(enc_len(len(w.encode())) + w.encode() for w in words) + b'\x00')

def nid():
    state['seq'] += 1
    return '*%X' % state['seq']

def handle(c):
    authed = False
    try:
        while True:
            w = read_sentence(c)
            if not w: continue
            cmd, attrs, q, tag = w[0], {}, {}, None
            for x in w[1:]:
                if x.startswith('='):
                    k, _, v = x[1:].partition('='); attrs[k] = v
                elif x.startswith('?'):
                    k, _, v = x[1:].partition('='); q[k] = v
                elif x.startswith('.tag='): tag = x[5:]
            t = ['.tag=' + tag] if tag else []
            def done(extra=None): send(c, ['!done'] + (['=ret=' + extra] if extra else []) + t)
            def trap(m): send(c, ['!trap', '=message=' + m] + t); done()
            def re(rows):
                for r in rows: send(c, ['!re'] + ['=%s=%s' % kv for kv in r.items()] + t)
                done()
            if cmd == '/login':
                if attrs.get('name') == 'marwa' and attrs.get('password') == 'Secret123': authed = True; done()
                else: trap('invalid user name or password (6)')
                continue
            if not authed: send(c, ['!fatal', 'not logged in']); return
            filt = lambda rows: [r for r in rows if all(r.get(k) == v for k, v in q.items())]
            with lock:
                if cmd == '/system/resource/print': re([{'uptime': '3d4h5m6s', 'version': '7.15.2 (stable)', 'cpu-load': '12', 'free-memory': '200000000', 'total-memory': '268435456', 'board-name': 'hAP ax2', 'cpu': 'ARM64', 'cpu-count': '4', 'architecture-name': 'arm64', 'free-hdd-space': '90000000', 'total-hdd-space': '128000000'}])
                elif cmd == '/system/identity/print': re([{'name': 'MikroTik-Test'}])
                elif cmd == '/system/routerboard/print': re([{'model': 'C52iG-5HaxD2HaxD', 'serial-number': 'HFX123'}])
                elif cmd == '/interface/print': re([{'.id': '*1', 'name': 'ether1', 'type': 'ether', 'mac-address': 'AA:BB:CC:00:00:01', 'mtu': '1500', 'running': 'true', 'disabled': 'false', 'rx-byte': '123456789', 'tx-byte': '987654321'}, {'.id': '*2', 'name': 'wlan1', 'type': 'wlan', 'running': 'true', 'disabled': 'false', 'rx-byte': '5', 'tx-byte': '6'}])
                elif cmd == '/interface/monitor-traffic': re([{'name': attrs.get('interface', ''), 'rx-bits-per-second': '2500000', 'tx-bits-per-second': '8200000'}])
                elif cmd == '/ip/hotspot/print': re([{'.id': '*1', 'name': 'hotspot1', 'interface': 'bridge', 'disabled': 'false'}])
                elif cmd == '/ip/hotspot/user/profile/print': re(filt(list(state['profiles'].values())))
                elif cmd == '/ip/hotspot/user/profile/add':
                    if attrs['name'] in state['profiles']: trap('failure: already have profile')
                    else: i = nid(); state['profiles'][attrs['name']] = dict(attrs, **{'.id': i}); done(i)
                elif cmd == '/ip/hotspot/user/profile/set':
                    for p in state['profiles'].values():
                        if p['.id'] == attrs['.id']: p.update({k: v for k, v in attrs.items() if k != '.id'})
                    done()
                elif cmd == '/ip/hotspot/user/add':
                    if attrs['name'] in state['users']: trap('failure: already have user with this name for this server')
                    else: i = nid(); state['users'][attrs['name']] = dict(attrs, **{'.id': i, 'uptime': '0s', 'bytes-in': '0', 'bytes-out': '0', 'disabled': attrs.get('disabled', 'false')}); done(i)
                elif cmd == '/ip/hotspot/user/print': re(filt([{k: v for k, v in u.items() if k != 'password'} for u in state['users'].values()]))
                elif cmd == '/ip/hotspot/user/set':
                    for u in state['users'].values():
                        if u['.id'] == attrs['.id']: u.update({k: v for k, v in attrs.items() if k != '.id'})
                    done()
                elif cmd == '/ip/hotspot/user/remove':
                    state['users'] = {k: u for k, u in state['users'].items() if u['.id'] != attrs['.id']}; done()
                elif cmd == '/ip/hotspot/active/print': re(filt(list(state['active'].values())))
                elif cmd == '/ip/hotspot/active/remove':
                    state['active'] = {k: a for k, a in state['active'].items() if a['.id'] != attrs['.id']}; done()
                elif cmd == '/log/print': re([{'time': '12:00:01', 'topics': 'hotspot,info', 'message': 'test log'}])
                elif cmd == '/system/reboot': done()
                elif cmd == '/test/login':  # simule une connexion client : usage du 1er ticket donné
                    u = state['users'][attrs['user']]; u['uptime'] = '25m'; u['bytes-in'] = '1000000'; u['bytes-out'] = '50000000'
                    i = nid(); state['active'][i] = {'.id': i, 'user': attrs['user'], 'address': '10.5.50.10', 'mac-address': 'DE:AD:BE:EF:00:01', 'uptime': '25m', 'bytes-in': '1000000', 'bytes-out': '50000000', 'server': 'hotspot1'}; done()
                else: trap('no such command')
    except (EOFError, ConnectionResetError, BrokenPipeError): pass
    finally: c.close()

s = socket.socket(); s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1); s.bind(('127.0.0.1', PORT)); s.listen(20)
while True:
    c, _ = s.accept(); threading.Thread(target=handle, args=(c,), daemon=True).start()
