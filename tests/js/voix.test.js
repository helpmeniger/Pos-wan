// Tests du cœur de MARWA VOICE AI (node tests/js/voix.test.js)
const V = require('../../addons/marwa_voice/public/voix-core.js');
let ok = 0, ko = 0;
function eq(a, b, m) { if (JSON.stringify(a) === JSON.stringify(b)) ok++; else { ko++; console.log('✘', m, '\n   attendu :', JSON.stringify(b), '\n   obtenu  :', JSON.stringify(a)); } }

// Nombres en lettres (règles du français)
const W = { 0: 'zéro', 1: 'un', 16: 'seize', 17: 'dix-sept', 21: 'vingt et un', 22: 'vingt-deux', 70: 'soixante-dix', 71: 'soixante et onze', 72: 'soixante-douze', 80: 'quatre-vingts', 81: 'quatre-vingt-un', 91: 'quatre-vingt-onze', 99: 'quatre-vingt-dix-neuf', 100: 'cent', 101: 'cent un', 200: 'deux cents', 201: 'deux cent un', 1000: 'mille', 1001: 'mille un', 2000: 'deux mille', 12500: 'douze mille cinq cents', 80000: 'quatre-vingt mille', 200000: 'deux cent mille', 425000: 'quatre cent vingt-cinq mille', 1000000: 'un million', 2500000: 'deux millions cinq cent mille', 1000000000: 'un milliard' };
for (const [n, w] of Object.entries(W)) eq(V.words(Number(n)), w, 'mots ' + n);
eq(V.words(-5), 'moins cinq', 'négatif');
eq(V.decimal(2.5), 'deux virgule cinq', 'décimal');
eq(V.decimal(1.05), 'un virgule zéro cinq', 'décimal avec zéro');

// Montants et quantités
eq(V.amount(12500), 'douze mille cinq cents francs CFA', 'montant FCFA');
eq(V.amount(1), 'un franc CFA', 'montant singulier');
eq(V.amount(12.5, 'EUR', 2), 'douze euros cinquante', 'montant avec centimes');
eq(V.quantity(3, 'unité'), 'trois unités', 'quantité accordée');
eq(V.quantity(1, 'sac'), 'un sac', 'quantité singulier');
eq(V.time('14:05'), 'quatorze heures cinq', 'heure');

// Normalisation de textes du serveur
eq(V.normalize('Stock disponible insuffisant (3).'), 'Stock disponible insuffisant (trois).', 'chiffres dans une phrase');
eq(V.normalize('Total 12 500 FCFA'), 'Total douze mille cinq cents francs CFA', 'séparateur de milliers et FCFA');
eq(V.normalize('Total 12 500 F'), 'Total douze mille cinq cents F', 'espace fine insécable');
eq(V.normalize('Vente V-2026-00012 du 3,5 kg'), 'Vente V-2026-00012 du trois virgule cinq kilos', 'référence conservée, unité en entier');
eq(V.normalize('Remise de 10%'), 'Remise de dix pour cent', 'pourcentage');
eq(V.normalize('ouvert à 08:30'), 'ouvert à huit heures trente', 'heure dans une phrase');
eq(V.normalize('a\u0007b'), 'a b', 'caractères de contrôle retirés');

// Phrases : uniquement les données transmises
eq(V.phrase('pos.sale.saved', { total: 12500, change: 2500 }), 'Vente enregistrée. Total : douze mille cinq cents francs CFA. Monnaie à rendre : deux mille cinq cents francs CFA.', 'vente enregistrée');
eq(V.phrase('pos.sale.saved', { total: 500, change: 0 }), 'Vente enregistrée. Total : cinq cents francs CFA.', 'vente sans monnaie');
eq(/déjà enregistrée/.test(V.phrase('pos.sale.saved', { duplicate: true, total: 1 })), true, 'vente en double');
eq(/n'est pas confirmée/.test(V.phrase('pos.sale.unknown', {})), true, 'coupure : état non confirmé');
eq(/enregistrée, mais l'impression/.test(V.phrase('pos.print.failed', {})), true, 'échec impression');
eq(V.phrase('inconnu', {}), null, 'événement inconnu non lu');

// Silence
const at = (h, m) => { const d = new Date(2026, 0, 1, h, m); return d; };
eq(V.inQuiet('22:00', '06:00', at(23, 0)), true, 'silence la nuit');
eq(V.inQuiet('22:00', '06:00', at(5, 59)), true, 'silence avant 6 h');
eq(V.inQuiet('22:00', '06:00', at(12, 0)), false, 'pas de silence à midi');
eq(V.inQuiet('12:00', '14:00', at(13, 0)), true, 'plage simple');
eq(V.inQuiet('', '', at(13, 0)), false, 'pas de plage');

// File : priorité, doublons, regroupement, expiration, interruption
let now = 1000000; const Q = V.Queue({ now: () => now });
Q.push({ event: 'a', level: 1, text: 'info' });
Q.push({ event: 'b', level: 3, text: 'erreur' });
Q.push({ event: 'c', level: 1, text: 'info 2' });
eq(Q.next().text, 'erreur', 'priorité : erreur d\'abord');
eq(Q.next().text, 'info', 'puis ordre d\'arrivée');
eq(Q.push({ event: 'c', level: 1, text: 'info 2' }).reason, 'doublon', 'doublon écarté');
now += 11000;
eq(Q.push({ event: 'c', level: 1, text: 'info 2' }).accepted, true, 'même message accepté après le délai');
Q.clear();
for (let i = 0; i < 20; i++) Q.push({ event: 'stock.low', level: 2, text: 'Produit ' + i, group: 'stock', groupText: (n) => n + ' produits sont sous leur seuil minimal.' });
eq(Q.length, 1, '20 alertes regroupées en une');
eq(Q.next().text, '20 produits sont sous leur seuil minimal.', 'texte du regroupement');
Q.push({ event: 'x', level: 0, text: 'vieille info' });
now += 9000;
eq(Q.next(), null, 'information périmée écartée');
eq(Q.takeDropped().some((d) => d.reason === 'expire'), true, 'expiration journalisée');
eq(Q.interrupts({ level: 4 }, { level: 1 }), true, 'critique interrompt');
eq(Q.interrupts({ level: 3 }, { level: 1 }), false, 'erreur n\'interrompt pas');
eq(Q.interrupts({ level: 4 }, { level: 4 }), false, 'critique n\'interrompt pas critique');
const big = V.Queue({ now: () => now, maxLen: 3 });
[0, 3, 1, 2].forEach((l, i) => big.push({ event: 'e' + i, level: l, text: 't' + i }));
eq(big.items.map((x) => x.level).sort(), [1, 2, 3], 'file pleine : le moins important écarté');

// Restaurant : cuisine et salle (données du bon uniquement)
const bon = { order_number: 'CMD2026-000024', table: '6', waiter: 'Awa', items: [{ quantity: 2, name: 'Poulet braisé', note: 'sans piment' }, { quantity: 3, name: 'Fanta 33 cl' }] };
eq(V.phrase('kitchen.ticket.new', bon), 'Nouvelle commande numéro vingt-quatre, table six. deux Poulet braisé, remarque : sans piment. trois Fanta trente-trois centilitres. Serveur : Awa.', 'cuisine : nouvelle commande');
eq(/^Ajout pour la commande numéro vingt-quatre/.test(V.phrase('kitchen.ticket.new', Object.assign({}, bon, { previous: 1 }))), true, 'cuisine : ajout à une commande déjà envoyée');
eq(V.phrase('kitchen.ticket.cancel', Object.assign({}, bon, { items: [{ quantity: 1, name: 'Poulet braisé', reason: 'client parti' }] })), 'Attention, annulation. Commande numéro vingt-quatre, table six. Ne pas préparer : un Poulet braisé, motif : client parti.', 'cuisine : annulation avec motif');
eq(V.phrase('kitchen.ticket.new', { order_number: 'CMD2026-000007', order_type: 'delivery', customer: 'Moussa', items: [{ quantity: 0.5, name: 'Viande (kg)' }] }), 'Nouvelle commande numéro sept, livraison, pour Moussa. un demi Viande (kilo).', 'cuisine : livraison, demi');
eq(V.phrase('kitchen.ticket.new', { order_number: 'X', items: [] }), null, 'bon sans plat : rien n\'est lu');
eq(/^Je répète\. Attention, annulation/.test(V.phrase('kitchen.ticket.repeat', Object.assign({ type: 'cancel' }, bon))), true, 'relecture d\'une annulation');
eq(V.phrase('restaurant.table.ready', { table: 'Terrasse 2', n: 3 }), 'Table Terrasse deux : trois plats prêts à servir.', 'salle : plats prêts');
eq(V.phrase('restaurant.table.ready', { order_number: 'CMD2026-000009', order_type: 'takeaway', customer: 'Awa', n: 1 }), 'Commande numéro neuf, à emporter, pour Awa : plat prêt à servir.', 'salle : à emporter');
eq(V.normalize('Sucre 1 kg, Huile 5 L, Lait 1,5 L, 500 g'), 'Sucre un kilo, Huile cinq litres, Lait un virgule cinq litre, cinq cents grammes', 'unités lues en entier, accord');
// File : une commande de cuisine (validité 10 min) n'est pas écartée par une longue file
let now2 = 0; const QK = V.Queue({ now: () => now2 });
QK.push({ event: 'kitchen.ticket.new', level: 1, text: 'bon 1', ttl: 600 }); QK.push({ event: 'pos.x', level: 1, text: 'info' });
now2 = 120000;
const nk = QK.next(); eq([nk && nk.text, QK.length], ['bon 1', 0], 'après 2 min : le bon de cuisine est encore lu, l\'annonce ordinaire est écartée');

console.log(`MARWA VOICE (cœur) : ${ok} réussi(s), ${ko} échec(s)`);
process.exit(ko ? 1 : 0);
