"""Test de bout en bout (base vide + simulateur RouterOS + serveur php -S sur le port 8099).
Nécessite une application NON installée (pas de .env) : à lancer uniquement sur un poste de test, jamais sur une installation réelle."""
import re, json, urllib.request, urllib.parse, http.cookiejar, hmac, hashlib, subprocess
B = 'http://127.0.0.1:8099'
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None
def client():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect)
op = client()
fails = []
def req(path, data=None, o=None, headers=None, raw=None):
    o = o or op
    body = raw if raw is not None else (urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None)
    r = urllib.request.Request(B + path, data=body, headers=headers or {})
    try:
        resp = o.open(r, timeout=60); return resp.status, resp.read().decode('utf-8', 'replace'), resp.headers
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8', 'replace'), e.headers
def token(html):
    m = re.search(r'name="_token" value="([^"]+)"', html); return m.group(1) if m else ''
def get(path, o=None, expect=200):
    s, h, hd = req(path, o=o)
    if s != expect:
        fails.append(f'GET {path} -> {s}'); print('FAIL GET', path, s, re.sub(r'\s+', ' ', h)[:400])
    return h
def post(path, data, page='/profile', o=None, expect=302):
    t = token(get(page, o=o)); data = dict(data); data['_token'] = t
    s, h, hd = req(path, data, o=o)
    if s != expect:
        fails.append(f'POST {path} -> {s}'); print('FAIL POST', path, s, re.sub(r'\s+', ' ', h)[:400])
    return hd.get('Location', '') or '', h
def flash(path='/', o=None):
    h = get(path, o=o)
    return [(a, b.strip()[:160]) for a, b in re.findall(r'alert-(success|danger|warning|info) alert-dismissible[^>]*>\s*<i[^>]*></i>\s*<div class="flex-grow-1">([^<]*)', h)]
def ajax(path, payload, page='/profile', o=None):
    t = token(get(page, o=o))
    s, h, _ = req(path, raw=json.dumps(payload).encode(), o=o, headers={'Content-Type': 'application/json', 'X-CSRF-Token': t, 'Accept': 'application/json'})
    try: return s, json.loads(h)
    except Exception: return s, {'text': h[:300]}
def sql(q):
    return subprocess.run(['mysql', '-uroot', '-N', 'marwa_connect', '-e', q], capture_output=True, text=True).stdout.strip()
def rel(loc): return loc.replace(B, '') or '/'

h = get('/install')
s, _, hd = req('/install', {'_token': token(h), 'db_host': '127.0.0.1', 'db_port': '3306', 'db_name': 'marwa_connect', 'db_user': 'marwa', 'db_password': 'Test1234', 'app_url': B,
    'company_name': 'MARWA', 'admin_name': 'Admin Test', 'admin_username': 'admin', 'admin_email': 'admin@test.ne', 'admin_password': 'Secret12345', 'admin_password_confirmation': 'Secret12345'})
print('install', s, hd.get('Location'))
h = get('/login'); s, _, hd = req('/login', {'_token': token(h), 'login': 'admin', 'password': 'Secret12345'}); print('login', s, hd.get('Location'))

for p in ['/', '/ai', '/monitoring', '/routers', '/hotspot', '/offers', '/tickets', '/pos', '/sales', '/resellers', '/notifications', '/admin/users', '/admin/roles', '/admin/audit', '/settings', '/admin/backups', '/tickets/generate', '/tickets/batches', '/tickets/templates', '/routers/create', '/offers/create', '/resellers/create', '/pos/sessions', '/sales?tab=online', '/buy']:
    get(p)
print('menu pages checked')

s, j = ajax('/routers/test', {'host': '127.0.0.1', 'api_port': '18728', 'api_ssl_port': '8729', 'api_username': 'marwa', 'api_password': 'Secret123', 'use_ssl': 0}, '/routers/create'); print('test', s, j.get('message'))
s, j = ajax('/routers/test', {'host': '127.0.0.1', 'api_port': '18728', 'api_ssl_port': '8729', 'api_username': 'marwa', 'api_password': 'bad', 'use_ssl': 0}, '/routers/create'); print('test bad', s, j.get('message'))
loc, _ = post('/routers', {'name': 'Routeur principal', 'host': '127.0.0.1', 'api_port': '18728', 'api_ssl_port': '8729', 'api_username': 'marwa', 'api_password': 'Secret123',
    'connection_type': 'lan', 'sync_interval_minutes': '5', 'status': 'active', 'is_default': '1', 'sync_enabled': '1', 'wifi_ssid': 'MARWA-WIFI', 'wan_interface': 'ether1', 'new_site': 'Niamey Centre'}, '/routers/create')
print('router ->', loc, flash(rel(loc)))
for tab in ['overview', 'interfaces', 'sessions', 'users', 'profiles', 'logs']: get('/routers/1?tab=' + tab)
post('/routers/1/sync', {}, '/routers/1'); print('sync', flash('/routers/1'))

for oid in (1, 3):
    h = get(f'/offers/{oid}/edit')
    code = re.search(r'name="code" value="([^"]+)"', h).group(1); name = re.search(r'id="name" name="name" value="([^"]+)"', h).group(1); price = re.search(r'name="price" value="([^"]+)"', h).group(1)
    post(f'/offers/{oid}', {'_method': 'PUT', 'name': name, 'code': code, 'price': price, 'validity_value': re.search(r'name="validity_value" value="([^"]*)"', h).group(1), 'validity_unit': 'hours' if oid == 1 else 'days',
        'shared_users': '1', 'activation_mode': 'first_login', 'validity_mode': 'elapsed_time', 'status': 'active', 'sort_order': '0', 'rate_down': '5M', 'rate_up': '2M', 'routers[]': ['1'], 'is_public': '1'}, f'/offers/{oid}/edit')
print('offers', flash('/offers'))

loc, _ = post('/tickets/generate', {'offer_id': '1', 'router_id': '1', 'quantity': '10', 'prefix': 'MC', 'username_length': '8', 'password_length': '6', 'charset': 'alphanumeric_upper', 'activation_mode': 'first_login', 'validity_mode': 'elapsed_time', 'shared_users': '1'}, '/tickets/generate')
print('gen', loc, flash(rel(loc)))
loc, _ = post('/tickets/generate', {'offer_id': '3', 'router_id': '1', 'quantity': 'custom', 'custom_quantity': '25', 'prefix': 'D3', 'username_length': '6', 'password_length': '6', 'charset': 'numeric', 'same_credentials': '1', 'activation_mode': 'first_login', 'validity_mode': 'elapsed_time', 'shared_users': '1'}, '/tickets/generate')
print('gen2', flash(rel(loc)))
print('on router:', sql("SELECT COUNT(*), SUM(on_router) FROM tickets"))
s, pdf, hd = req('/tickets/print?batch_id=1&output=pdf'); print('pdf', s, hd.get('Content-Type'), pdf[:8], len(pdf))
s, html, _ = req('/tickets/print?batch_id=1&format=sheet20'); print('print html', s, html.count('class="tk"'))
s, csv, _ = req('/tickets/export?batch_id=1&only=all'); print('csv lines', len(csv.strip().splitlines()))
get('/tickets/1')

h = get('/pos'); print('pos closed shown:', 'Caisse fermée' in h)
post('/pos/open', {'opening_amount': '5000'}, '/pos')
h = get('/pos'); idem = re.search(r'name="idempotency_key" value="([^"]+)"', h).group(1)
data = {'offer_id': '1', 'router_id': '1', 'quantity': '3', 'payment_method_id': '1', 'idempotency_key': idem, 'customer_phone': '+22790000000'}
loc, _ = post('/pos/sell', data, '/pos'); print('sale ->', loc)
loc2, _ = post('/pos/sell', data, '/pos'); print('double submit returns same sale:', loc == loc2)
sale_id = re.search(r'/sales/(\d+)', loc).group(1)
s, rec, _ = req(f'/sales/{sale_id}/receipt'); print('receipt', s, rec.count('class="tkr"'))
post('/pos/sell', {'offer_id': '1', 'router_id': '1', 'quantity': '1', 'payment_method_id': '2'}, '/pos'); print('mm (inactive) :', flash('/pos'))
post('/pos/sell', {'offer_id': '1', 'router_id': '1', 'quantity': '50', 'payment_method_id': '1'}, '/pos'); print('overstock:', flash('/pos'))
loc, _ = post('/pos/sell', {'offer_id': '3', 'router_id': '1', 'quantity': '1', 'payment_method_id': '1'}, '/pos'); sid2 = re.search(r'/sales/(\d+)', loc).group(1)
post(f'/sales/{sid2}/cancel', {'reason': 'Erreur de saisie'}, f'/sales/{sid2}'); print('cancel', flash(f'/sales/{sid2}'))
loc, _ = post('/pos/sell', {'offer_id': '3', 'router_id': '1', 'quantity': '1', 'payment_method_id': '1'}, '/pos'); sid3 = re.search(r'/sales/(\d+)', loc).group(1)
post(f'/sales/{sid3}/refund', {'amount': '500', 'reason': 'Client mécontent'}, f'/sales/{sid3}'); print('refund', flash(f'/sales/{sid3}'))
post('/pos/close', {'declared_amount': '6000', 'notes': 'test'}, '/pos'); print('close', flash('/pos/sessions/1'))

sold = sql(f'SELECT username FROM tickets WHERE sale_id={sale_id} LIMIT 1')
subprocess.run(['php', '-r', f"require '/home/user/Pos-wan/marwa-connect/bootstrap/app.php'; $c=new App\\Services\\MikroTik\\RouterOSClient('127.0.0.1',18728); $c->connect('marwa','Secret123'); $c->exec('/test/login',['user'=>'{sold}']);"])
post('/routers/1/sync', {}, '/routers/1'); print('sync2', flash('/routers/1'))
print('ticket after use:', sql(f"SELECT status, first_used_at IS NOT NULL, expires_at, uptime_seconds FROM tickets WHERE username='{sold}'"))
h = get('/hotspot'); print('hotspot shows client:', sold in h)
get('/hotspot?tab=history'); get('/hotspot?tab=top')
s, h, _ = req('/monitoring/1/live', headers={'Accept': 'application/json'}); print('live', s, h[:170])
s, h, _ = req('/monitoring/1/history', headers={'Accept': 'application/json'}); print('history', s, len(json.loads(h)['rows']))

loc, _ = post('/resellers', {'business_name': 'Boutique Awa', 'code': 'REV01', 'phone': '90112233', 'email': 'awa@rev.ne', 'business_model': 'commission', 'commission_rate': '10', 'wholesale_discount': '0', 'credit_limit': '0', 'routers[]': ['1'], 'username': 'awa', 'password': 'Revendeur123'}, '/resellers/create')
print('reseller', loc, flash(rel(loc)))
post('/resellers/1/stock', {'mode': 'assign', 'offer_id': '3', 'router_id': '1', 'quantity': '5'}, '/resellers/1'); print('stock', flash('/resellers/1'))
post('/resellers', {'business_name': 'Grossiste Ali', 'code': 'REV02', 'business_model': 'wholesale', 'commission_rate': '0', 'wholesale_discount': '20', 'credit_limit': '0', 'routers[]': ['1']}, '/resellers/create')
post('/resellers/2/stock', {'mode': 'assign', 'offer_id': '3', 'router_id': '1', 'quantity': '2'}, '/resellers/2'); print('wholesale no balance', flash('/resellers/2'))
post('/resellers/2/topup', {'type': 'topup', 'amount': '1000', 'note': 'Versement'}, '/resellers/2')
post('/resellers/2/stock', {'mode': 'assign', 'offer_id': '3', 'router_id': '1', 'quantity': '2'}, '/resellers/2'); print('wholesale', flash('/resellers/2'))
o2 = client()
h = get('/login', o=o2); req('/login', {'_token': token(h), 'login': 'awa', 'password': 'Revendeur123'}, o=o2)
post('/profile/password', {'current_password': 'Revendeur123', 'password': 'Revendeur456', 'password_confirmation': 'Revendeur456'}, '/profile', o=o2)
h = get('/reseller', o=o2); tid = re.search(r'data-portal-sell="(\d+)"', h).group(1)
post('/reseller/sell', {'ticket_id': tid, 'phone': '96000000'}, '/reseller', o=o2); print('portal sell', flash('/reseller', o=o2))
print('reseller balance:', sql('SELECT business_name, balance FROM resellers'))
s, h, hd = req('/', o=o2); print('reseller / ->', s, hd.get('Location'))
s, h, _ = req('/routers', o=o2); print('reseller /routers ->', s)
s, h, _ = req(f'/tickets/print?ids[]={tid}&ids[]=1', o=o2); print('reseller print own only:', h.count('class="tk"'))

for q in ['aide', 'Combien de clients sont connectés actuellement ?', "Quel est le chiffre d'affaires du mois ?", 'Quel routeur a généré le plus de revenus cette semaine ?', "Affiche les tickets vendus aujourd'hui", 'Quels routeurs sont déconnectés ?', 'Quels forfaits sont les plus vendus ?', 'Affiche les clients qui consomment le plus de données', 'Quel est le stock de tickets ?', 'Génère 5 tickets de 100 FCFA sur le routeur principal', 'blabla']:
    s, j = ajax('/ai/ask', {'question': q}, '/ai'); print('AI>', q, '|', s, j.get('text', '')[:110].replace('\n', ' '), '| rows', len((j.get('table') or {}).get('rows', [])), '| action', j.get('action_id'))
    if j.get('action_id'):
        s, j2 = ajax(f"/ai/actions/{j['action_id']}", {'decision': 'confirm'}, '/ai'); print('   confirm ->', j2.get('text'))

post('/settings/company', {'company__name': 'MARWA NET', 'company__slogan': 'x', 'company__phone': '+227', 'company__email': 'a@b.ne', 'company__address': 'Niamey'}, '/settings'); print('settings', flash('/settings'))
post('/settings/appearance', {'appearance__primary_color': '#C0102A', 'appearance__nav_color': '#0B1A33', 'appearance__default_theme': 'light'}, '/settings?tab=appearance')
post('/settings/payments/2', {'name': 'Nita', 'is_active': '1', 'available_pos': '1', 'available_online': '1', 'merchant_number': '90909090', 'webhook_secret': 'whsec_test'}, '/settings?tab=payments'); print('payment', flash('/settings?tab=payments'))
post('/notifications/rules', {'rules[2][enabled]': '1', 'rules[2][threshold]': '5', 'rules[2][cooldown]': '0'}, '/notifications?tab=rules'); print('rules', flash('/notifications?tab=rules'))
post('/notifications/channels/email', {'is_active': '1', 'host': '127.0.0.1', 'port': '2525', 'encryption': 'none', 'from': 'a@b.ne', 'recipients': 'x@y.ne'}, '/notifications?tab=channels')
post('/admin/backups', {}, '/admin/backups'); print('backup', flash('/admin/backups'))
post('/tickets/templates/3', {'name': 'Planche de 20', 'format': 'sheet20', 'accent': '#112233', 'fields[]': ['offer', 'username', 'password', 'price']}, '/tickets/templates')
for p in ['/', '/?period=7d', '/?period=30d', '/?period=custom&from=2026-10-01&to=2026-11-02', '/sales', '/sales/export', '/tickets?status=sold', '/notifications', '/notifications?tab=rules', '/notifications?tab=channels', '/settings?tab=ai', '/settings?tab=payments', '/resellers/1', '/resellers/2', '/pos/sessions/1', '/tickets/batches/1', '/ai', '/monitoring']:
    get(p)

pub = client()
h = get('/buy', o=pub)
s, _, hd = req('/buy', {'_token': token(h), 'offer_id': '3', 'router_id': '1', 'payment_method_id': '2', 'phone': '+22791234567'}, o=pub)
order_url = hd.get('Location') or ''; print('order', s, order_url)
ref = re.search(r'/buy/order/(\w+)', order_url).group(1)
body = json.dumps({'event_id': 'evt_1', 'order_reference': ref, 'provider_reference': 'NITA-TX-777', 'status': 'success', 'amount': 500}).encode()
s, h, _ = req('/webhooks/payments/nita', raw=body, headers={'Content-Type': 'application/json', 'X-Signature': 'bad'}); print('webhook bad sig', s)
sig = hmac.new(b'whsec_test', body, hashlib.sha256).hexdigest()
s, h, _ = req('/webhooks/payments/nita', raw=body, headers={'Content-Type': 'application/json', 'X-Signature': sig}); print('webhook ok', s, h)
s, h, _ = req('/webhooks/payments/nita', raw=body, headers={'Content-Type': 'application/json', 'X-Signature': sig}); print('webhook replay', s, h)
s, h, _ = req(rel(order_url), o=pub); print('order page delivered:', 'Paiement confirmé' in h)
s, h, _ = req('/buy/order/' + ref + '?t=wrong', o=pub); print('wrong token ->', s)
print('FAILS:', fails)
