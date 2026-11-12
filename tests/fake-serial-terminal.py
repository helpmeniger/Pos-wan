"""Faux terminal série pour les tests : ouvre une paire pty, affiche le nom du port, répond en JSON d'une ligne.
Aucun lien avec un terminal réel ; sert à vérifier le pont local Marwa Pay Bridge."""
import json, os, pty, sys, tty
m, s = pty.openpty()
tty.setraw(s)
print(os.ttyname(s), flush=True)
buf = b''
while True:
    data = os.read(m, 1024)
    if not data:
        break
    buf += data
    while b'\n' in buf:
        line, buf = buf.split(b'\n', 1)
        try:
            req = json.loads(line.decode())
        except Exception:
            os.write(m, b'{"result":"ERROR"}\n'); continue
        if req.get('op') == 'ping':
            resp = {'result': 'READY'}
        else:
            resp = {'result': 'APPROVED', 'stan': 'USB-' + str(req.get('ref', '')), 'auth': 'U55', 'amount': req.get('amount'), 'last4': '1881'}
        os.write(m, (json.dumps(resp) + '\n').encode())
