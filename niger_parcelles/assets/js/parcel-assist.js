/*
 * Niger Parcelles - moteur d'assistance à la délimitation (sans dépendance).
 *
 * - Conversions Web Mercator (pixels <-> lat/lng), UTM WGS84 31N/32N/33N.
 * - Détection d'une parcelle sur l'image satellite à partir d'un clic :
 *   croissance de région (couleur + contours), fermeture morphologique,
 *   enveloppe convexe, rectangle d'aire minimale, score combinant la
 *   rectangularité et la taille usuelle des parcelles (400 m² par défaut).
 * - Orientation dominante (tenseur de structure des gradients).
 * - Gabarits rectangulaires (20 × 20 m = 400 m²…), découpage en lots,
 *   équerrage, ajustement à une superficie cible.
 *
 * Les résultats sont des PROPOSITIONS à vérifier et ajuster : l'image
 * satellite ne prouve ni une limite juridique ni un droit de propriété.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) module.exports = factory();
    else root.NPAssist = factory();
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    const TILE = 256;
    const R_MERC = 6378137;

    // ------------------------------------------------------------ Mercator
    function latLngToPixel(lat, lng, z) {
        const s = TILE * Math.pow(2, z);
        const sin = Math.min(Math.max(Math.sin(lat * Math.PI / 180), -0.9999), 0.9999);
        return {
            x: (lng + 180) / 360 * s,
            y: (0.5 - Math.log((1 + sin) / (1 - sin)) / (4 * Math.PI)) * s,
        };
    }
    function pixelToLatLng(x, y, z) {
        const s = TILE * Math.pow(2, z);
        const lng = x / s * 360 - 180;
        const n = Math.PI - 2 * Math.PI * y / s;
        const lat = 180 / Math.PI * Math.atan(0.5 * (Math.exp(n) - Math.exp(-n)));
        return { lat, lng };
    }
    /** Mètres par pixel (résolution au sol) à une latitude et un zoom donnés. */
    function metersPerPixel(lat, z) {
        return 2 * Math.PI * R_MERC * Math.cos(lat * Math.PI / 180) / (TILE * Math.pow(2, z));
    }

    // ------------------------------------------------------------ UTM WGS84
    const A = 6378137, F = 1 / 298.257223563, K0 = 0.9996;
    const E2 = F * (2 - F), EP2 = E2 / (1 - E2);
    function utmZone(lng) { return Math.floor((lng + 180) / 6) + 1; }
    function toUtm(lat, lng, zone) {
        zone = zone || utmZone(lng);
        const lon0 = ((zone - 1) * 6 - 180 + 3) * Math.PI / 180;
        const phi = lat * Math.PI / 180, lam = lng * Math.PI / 180;
        const N = A / Math.sqrt(1 - E2 * Math.sin(phi) ** 2);
        const T = Math.tan(phi) ** 2, C = EP2 * Math.cos(phi) ** 2, Aa = Math.cos(phi) * (lam - lon0);
        const M = A * ((1 - E2 / 4 - 3 * E2 ** 2 / 64 - 5 * E2 ** 3 / 256) * phi
            - (3 * E2 / 8 + 3 * E2 ** 2 / 32 + 45 * E2 ** 3 / 1024) * Math.sin(2 * phi)
            + (15 * E2 ** 2 / 256 + 45 * E2 ** 3 / 1024) * Math.sin(4 * phi)
            - (35 * E2 ** 3 / 3072) * Math.sin(6 * phi));
        const x = K0 * N * (Aa + (1 - T + C) * Aa ** 3 / 6 + (5 - 18 * T + T * T + 72 * C - 58 * EP2) * Aa ** 5 / 120) + 500000;
        let y = K0 * (M + N * Math.tan(phi) * (Aa * Aa / 2 + (5 - T + 9 * C + 4 * C * C) * Aa ** 4 / 24
            + (61 - 58 * T + T * T + 600 * C - 330 * EP2) * Aa ** 6 / 720));
        if (lat < 0) y += 10000000;
        return { x, y, zone };
    }
    function fromUtm(easting, northing, zone) {
        const x = easting - 500000, y = northing;
        const lon0 = ((zone - 1) * 6 - 180 + 3) * Math.PI / 180;
        const mu = (y / K0) / (A * (1 - E2 / 4 - 3 * E2 ** 2 / 64 - 5 * E2 ** 3 / 256));
        const e1 = (1 - Math.sqrt(1 - E2)) / (1 + Math.sqrt(1 - E2));
        const phi1 = mu + (3 * e1 / 2 - 27 * e1 ** 3 / 32) * Math.sin(2 * mu) + (21 * e1 * e1 / 16 - 55 * e1 ** 4 / 32) * Math.sin(4 * mu)
            + (151 * e1 ** 3 / 96) * Math.sin(6 * mu) + (1097 * e1 ** 4 / 512) * Math.sin(8 * mu);
        const N1 = A / Math.sqrt(1 - E2 * Math.sin(phi1) ** 2), T1 = Math.tan(phi1) ** 2, C1 = EP2 * Math.cos(phi1) ** 2;
        const R1 = A * (1 - E2) / Math.pow(1 - E2 * Math.sin(phi1) ** 2, 1.5), D = x / (N1 * K0);
        const lat = phi1 - (N1 * Math.tan(phi1) / R1) * (D * D / 2 - (5 + 3 * T1 + 10 * C1 - 4 * C1 * C1 - 9 * EP2) * D ** 4 / 24
            + (61 + 90 * T1 + 298 * C1 + 45 * T1 * T1 - 252 * EP2 - 3 * C1 * C1) * D ** 6 / 720);
        const lng = lon0 + (D - (1 + 2 * T1 + C1) * D ** 3 / 6 + (5 - 2 * C1 + 28 * T1 - 3 * C1 * C1 + 8 * EP2 + 24 * T1 * T1) * D ** 5 / 120) / Math.cos(phi1);
        return { lat: lat * 180 / Math.PI, lng: lng * 180 / Math.PI };
    }
    // ------------------------------------------------------------ Mesures ellipsoïdales (WGS84)
    // Mêmes formules que le serveur (includes/lib/Geo.php) : écart < 0,2 ppm avec
    // l'algorithme de référence de Karney pour des parcelles de taille courante.
    const E = Math.sqrt(E2);
    function authalic(phi) {
        const s = Math.sin(phi);
        return A * A * (1 - E2) * (s / (2 * (1 - E2 * s * s)) + Math.log((1 + E * s) / (1 - E * s)) / (4 * E));
    }
    function meridianArc(phi) {
        return A * ((1 - E2 / 4 - 3 * E2 ** 2 / 64 - 5 * E2 ** 3 / 256) * phi - (3 * E2 / 8 + 3 * E2 ** 2 / 32 + 45 * E2 ** 3 / 1024) * Math.sin(2 * phi)
            + (15 * E2 ** 2 / 256 + 45 * E2 ** 3 / 1024) * Math.sin(4 * phi) - (35 * E2 ** 3 / 3072) * Math.sin(6 * phi));
    }
    const toLL = (p) => (Array.isArray(p) ? { lat: p[1], lng: p[0] } : p);
    /** Superficie (m²) d'un anneau : [{lat,lng}] ou [[lng,lat]] (fermé ou non). */
    function geodesicArea(ring) {
        const pts = ring.map(toLL);
        if (pts.length > 1 && pts[0].lat === pts[pts.length - 1].lat && pts[0].lng === pts[pts.length - 1].lng) pts.pop();
        let t = 0;
        for (let i = 0; i < pts.length; i++) {
            const p = pts[i], q = pts[(i + 1) % pts.length];
            t += (q.lng - p.lng) * Math.PI / 180 * (authalic(p.lat * Math.PI / 180) + authalic(q.lat * Math.PI / 180)) / 2;
        }
        return Math.abs(t);
    }
    /** Distance (m) entre deux points proches. */
    function geodesicDistance(a, b) {
        a = toLL(a); b = toLL(b);
        const pm = (a.lat + b.lat) / 2 * Math.PI / 180;
        const n = A / Math.sqrt(1 - E2 * Math.sin(pm) ** 2);
        return Math.hypot((b.lng - a.lng) * Math.PI / 180 * n * Math.cos(pm), meridianArc(b.lat * Math.PI / 180) - meridianArc(a.lat * Math.PI / 180));
    }
    /** Longueur d'une ligne (closed=true : périmètre d'un anneau). */
    function geodesicLength(pts, closed) {
        let s = 0;
        for (let i = 1; i < pts.length; i++) s += geodesicDistance(pts[i - 1], pts[i]);
        if (closed && pts.length > 2) s += geodesicDistance(pts[pts.length - 1], pts[0]);
        return s;
    }

    /** Degrés décimaux -> « 13° 30' 41,76" N ». */
    function toDms(value, isLat) {
        const hemi = isLat ? (value >= 0 ? 'N' : 'S') : (value >= 0 ? 'E' : 'O');
        let v = Math.abs(value);
        const d = Math.floor(v); v = (v - d) * 60;
        const m = Math.floor(v); const s = (v - m) * 60;
        return d + '° ' + String(m).padStart(2, '0') + "' " + s.toFixed(2).replace('.', ',').padStart(5, '0') + '" ' + hemi;
    }

    // ------------------------------------------------------------ Géométrie plane
    function convexHull(points) {
        const pts = points.slice().sort((a, b) => a[0] - b[0] || a[1] - b[1]);
        if (pts.length < 3) return pts;
        const cross = (o, a, b) => (a[0] - o[0]) * (b[1] - o[1]) - (a[1] - o[1]) * (b[0] - o[0]);
        const lower = [];
        for (const p of pts) { while (lower.length >= 2 && cross(lower[lower.length - 2], lower[lower.length - 1], p) <= 0) lower.pop(); lower.push(p); }
        const upper = [];
        for (let i = pts.length - 1; i >= 0; i--) { const p = pts[i]; while (upper.length >= 2 && cross(upper[upper.length - 2], upper[upper.length - 1], p) <= 0) upper.pop(); upper.push(p); }
        upper.pop(); lower.pop();
        return lower.concat(upper);
    }
    function polygonArea(pts) {
        let s = 0;
        for (let i = 0; i < pts.length; i++) { const a = pts[i], b = pts[(i + 1) % pts.length]; s += a[0] * b[1] - b[0] * a[1]; }
        return Math.abs(s) / 2;
    }
    /** Rectangle d'aire minimale englobant des points (pieds à coulisse tournants). */
    function minAreaRect(points) {
        const hull = convexHull(points);
        if (hull.length < 3) return null;
        let best = null;
        for (let i = 0; i < hull.length; i++) {
            const a = hull[i], b = hull[(i + 1) % hull.length];
            const ang = Math.atan2(b[1] - a[1], b[0] - a[0]);
            const c = Math.cos(ang), s = Math.sin(ang);
            let minU = Infinity, maxU = -Infinity, minV = Infinity, maxV = -Infinity;
            for (const p of hull) {
                const u = p[0] * c + p[1] * s, v = -p[0] * s + p[1] * c;
                if (u < minU) minU = u; if (u > maxU) maxU = u; if (v < minV) minV = v; if (v > maxV) maxV = v;
            }
            const area = (maxU - minU) * (maxV - minV);
            if (!best || area < best.area) {
                const cu = (minU + maxU) / 2, cv = (minV + maxV) / 2;
                best = { area, angle: ang, w: maxU - minU, h: maxV - minV, cx: cu * c - cv * s, cy: cu * s + cv * c };
            }
        }
        best.corners = rectCorners(best.cx, best.cy, best.w, best.h, best.angle);
        best.hullArea = polygonArea(hull);
        return best;
    }
    function rectCorners(cx, cy, w, h, angle) {
        const c = Math.cos(angle), s = Math.sin(angle);
        return [[-w / 2, -h / 2], [w / 2, -h / 2], [w / 2, h / 2], [-w / 2, h / 2]]
            .map(([u, v]) => [cx + u * c - v * s, cy + u * s + v * c]);
    }

    // ------------------------------------------------------------ Analyse d'image
    /** Flou 3×3 + luminance + gradients de Sobel. */
    function preprocess(rgba, w, h) {
        const n = w * h;
        const r = new Float32Array(n), g = new Float32Array(n), b = new Float32Array(n);
        for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
            let sr = 0, sg = 0, sb = 0, k = 0;
            for (let dy = -1; dy <= 1; dy++) for (let dx = -1; dx <= 1; dx++) {
                const xx = x + dx, yy = y + dy;
                if (xx < 0 || yy < 0 || xx >= w || yy >= h) continue;
                const o = (yy * w + xx) * 4; sr += rgba[o]; sg += rgba[o + 1]; sb += rgba[o + 2]; k++;
            }
            const i = y * w + x; r[i] = sr / k; g[i] = sg / k; b[i] = sb / k;
        }
        const lum = new Float32Array(n), gx = new Float32Array(n), gy = new Float32Array(n), mag = new Float32Array(n);
        for (let i = 0; i < n; i++) lum[i] = 0.299 * r[i] + 0.587 * g[i] + 0.114 * b[i];
        for (let y = 1; y < h - 1; y++) for (let x = 1; x < w - 1; x++) {
            const i = y * w + x;
            const L = (dx, dy) => lum[i + dy * w + dx];
            const sx = -L(-1, -1) - 2 * L(-1, 0) - L(-1, 1) + L(1, -1) + 2 * L(1, 0) + L(1, 1);
            const sy = -L(-1, -1) - 2 * L(0, -1) - L(1, -1) + L(-1, 1) + 2 * L(0, 1) + L(1, 1);
            gx[i] = sx; gy[i] = sy; mag[i] = Math.hypot(sx, sy) / 4;
        }
        return { w, h, r, g, b, gx, gy, mag };
    }

    /** Orientation dominante (radians, modulo 90°) dans une fenêtre autour de (cx, cy). */
    function dominantOrientation(pre, cx, cy, radius) {
        let jxx = 0, jyy = 0, jxy = 0;
        const x0 = Math.max(1, Math.round(cx - radius)), x1 = Math.min(pre.w - 2, Math.round(cx + radius));
        const y0 = Math.max(1, Math.round(cy - radius)), y1 = Math.min(pre.h - 2, Math.round(cy + radius));
        for (let y = y0; y <= y1; y++) for (let x = x0; x <= x1; x++) {
            const i = y * pre.w + x;
            // On travaille sur l'angle quadruplé pour rendre 0°, 90°, 180°, 270° équivalents
            const gx = pre.gx[i], gy = pre.gy[i];
            const m2 = gx * gx + gy * gy;
            if (m2 < 400) continue;
            const a = Math.atan2(gy, gx) * 4;
            jxx += Math.sqrt(m2) * Math.cos(a); jyy += Math.sqrt(m2) * Math.sin(a); jxy += Math.sqrt(m2);
        }
        if (jxy === 0) return { angle: 0, strength: 0 };
        const angle = Math.atan2(jyy, jxx) / 4;
        return { angle: ((angle % (Math.PI / 2)) + Math.PI / 2) % (Math.PI / 2), strength: Math.hypot(jxx, jyy) / jxy };
    }

    /** Croissance de région depuis (sx, sy) avec tolérance de couleur et arrêt sur les contours forts. */
    function growRegion(pre, sx, sy, tol, edgeMax, maxRadius) {
        const { w, h, r, g, b, mag } = pre;
        const mask = new Uint8Array(w * h);
        const seed = sy * w + sx;
        // Couleur de référence : moyenne 5×5 autour du clic
        let mr = 0, mg = 0, mb = 0, k = 0;
        for (let dy = -2; dy <= 2; dy++) for (let dx = -2; dx <= 2; dx++) {
            const x = sx + dx, y = sy + dy;
            if (x < 0 || y < 0 || x >= w || y >= h) continue;
            const i = y * w + x; mr += r[i]; mg += g[i]; mb += b[i]; k++;
        }
        mr /= k; mg /= k; mb /= k;
        const queue = new Int32Array(w * h);
        let qh = 0, qt = 0, count = 0, leaked = false;
        queue[qt++] = seed; mask[seed] = 1;
        const r2 = maxRadius * maxRadius;
        while (qh < qt) {
            const i = queue[qh++]; count++;
            const x = i % w, y = (i - x) / w;
            // Moyenne glissante (s'adapte aux variations douces de la cour)
            mr += (r[i] - mr) / Math.min(count, 400); mg += (g[i] - mg) / Math.min(count, 400); mb += (b[i] - mb) / Math.min(count, 400);
            const nb = [i - 1, i + 1, i - w, i + w];
            for (let t = 0; t < 4; t++) {
                const j = nb[t];
                if (j < 0 || j >= w * h || mask[j]) continue;
                const jx = j % w, jy = (j - jx) / w;
                if (Math.abs(jx - x) > 1) continue;
                if ((jx - sx) * (jx - sx) + (jy - sy) * (jy - sy) > r2) { leaked = true; continue; }
                if (mag[j] > edgeMax) continue;
                const d = Math.hypot(r[j] - mr, g[j] - mg, b[j] - mb);
                if (d > tol) continue;
                mask[j] = 1; queue[qt++] = j;
            }
        }
        return { mask, count, leaked };
    }

    /** Fermeture morphologique (dilatation puis érosion) dans une boîte englobante. */
    function closeMask(mask, w, h, radius) {
        const dil = new Uint8Array(w * h), out = new Uint8Array(w * h);
        for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
            if (!mask[y * w + x]) continue;
            for (let dy = -radius; dy <= radius; dy++) for (let dx = -radius; dx <= radius; dx++) {
                const xx = x + dx, yy = y + dy;
                if (xx >= 0 && yy >= 0 && xx < w && yy < h) dil[yy * w + xx] = 1;
            }
        }
        for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
            let all = 1;
            for (let dy = -radius; dy <= radius && all; dy++) for (let dx = -radius; dx <= radius; dx++) {
                const xx = x + dx, yy = y + dy;
                if (xx < 0 || yy < 0 || xx >= w || yy >= h || !dil[yy * w + xx]) { all = 0; break; }
            }
            out[y * w + x] = all;
        }
        return out;
    }

    /**
     * Détecte la parcelle contenant (sx, sy) dans une image RGBA.
     * @param {object} opt mpp (mètres/pixel), targetArea (m², 400), minArea, maxArea
     * @returns {{corners:number[][], area:number, score:number, rectangularity:number, angle:number}|null}
     */
    function detectParcel(rgba, w, h, sx, sy, opt) {
        const mpp = opt.mpp;
        const target = opt.targetArea || 400;
        const minArea = opt.minArea || 60;
        const maxArea = opt.maxArea || 5000;
        const pre = opt.pre || preprocess(rgba, w, h);
        const maxRadius = Math.sqrt(maxArea) / mpp; // ~ demi-diagonale large
        let best = null;
        for (const tol of [12, 18, 24, 32, 42, 54]) {
            for (const edge of [18, 28, 40]) {
                const reg = growRegion(pre, sx, sy, tol, edge, maxRadius);
                const area0 = reg.count * mpp * mpp;
                if (area0 < minArea * 0.3 || area0 > maxArea * 1.5) continue;
                const closed = closeMask(reg.mask, w, h, Math.max(1, Math.round(0.8 / mpp)));
                const pts = [];
                let filled = 0;
                for (let y = 1; y < h - 1; y++) for (let x = 1; x < w - 1; x++) {
                    const i = y * w + x;
                    if (!closed[i]) continue;
                    filled++;
                    if (!closed[i - 1] || !closed[i + 1] || !closed[i - w] || !closed[i + w]) pts.push([x, y]);
                }
                if (pts.length < 8) continue;
                const rect = minAreaRect(pts);
                if (!rect) continue;
                const area = rect.area * mpp * mpp;
                if (area < minArea || area > maxArea) continue;
                const rectangularity = Math.min(1, filled / Math.max(rect.area, 1));
                const lr = Math.log(area / target);
                const sizePrior = Math.exp(-(lr * lr) / (2 * 0.55 * 0.55));
                const aspect = Math.min(rect.w, rect.h) / Math.max(rect.w, rect.h);
                const score = rectangularity * (0.35 + 0.65 * sizePrior) * (0.6 + 0.4 * aspect) * (reg.leaked ? 0.4 : 1);
                if (!best || score > best.score) {
                    best = { rect, area, score, rectangularity, angle: rect.angle, w: rect.w * mpp, h: rect.h * mpp, tol, edge };
                }
            }
        }
        if (best) {
            // La région couvre la cour jusqu'à la face intérieure des murs (moins le flou) :
            // on recule chaque côté jusqu'à l'axe du mur, limite usuelle entre concessions.
            const off = (opt.wallOffsetM !== undefined ? opt.wallOffsetM : 1.5 * mpp + 0.12) / mpp;
            const r = best.rect;
            best.corners = rectCorners(r.cx, r.cy, r.w + 2 * off, r.h + 2 * off, r.angle);
            best.w = (r.w + 2 * off) * mpp;
            best.h = (r.h + 2 * off) * mpp;
            best.area = best.w * best.h;
            best.orientation = dominantOrientation(pre, sx, sy, 30 / mpp);
        }
        return best;
    }

    // ------------------------------------------------------------ Repère métrique local (ellipsoïde)
    /**
     * Repère plan local centré sur (lat0, lng0) : x vers l'est, y vers le NORD, en mètres
     * sur l'ellipsoïde WGS84 (arc de méridien exact, rayon transverse local).
     * Contrairement au Web Mercator (anisotrope d'environ 0,6 % à la latitude du Niger),
     * un carré de 20 m y fait réellement 20 m de côté.
     */
    function localFrame(lat0, lng0) {
        const p0 = lat0 * Math.PI / 180;
        const arc0 = meridianArc(p0);
        return {
            toXY(p) {
                p = toLL(p);
                const ph = p.lat * Math.PI / 180;
                const n = A / Math.sqrt(1 - E2 * Math.sin(ph) ** 2);
                return [(p.lng - lng0) * Math.PI / 180 * n * Math.cos(ph), meridianArc(ph) - arc0];
            },
            toLL(xy) {
                // inversion de l'arc de méridien par Newton, puis longitude
                let ph = p0 + xy[1] / A;
                for (let i = 0; i < 6; i++) {
                    const w = Math.sqrt(1 - E2 * Math.sin(ph) ** 2);
                    ph -= (meridianArc(ph) - arc0 - xy[1]) / (A * (1 - E2) / (w * w * w));
                }
                const n = A / Math.sqrt(1 - E2 * Math.sin(ph) ** 2);
                return { lat: ph * 180 / Math.PI, lng: lng0 + xy[0] / (n * Math.cos(ph)) * 180 / Math.PI };
            },
        };
    }
    function centroidLL(latlngs) {
        const pts = latlngs.map(toLL);
        return { lat: pts.reduce((s, p) => s + p.lat, 0) / pts.length, lng: pts.reduce((s, p) => s + p.lng, 0) / pts.length };
    }

    // ------------------------------------------------------------ Gabarits et lots
    /**
     * Rectangle wM × hM mètres centré sur (lat, lng), tourné de `angle` radians
     * (convention écran : sens horaire vu du nord, comme les angles de l'image satellite).
     */
    function rectangleLatLngs(lat, lng, wM, hM, angle) {
        const f = localFrame(lat, lng);
        // rectCorners travaille en repère « écran » (y vers le bas) : on inverse y
        return rectCorners(0, 0, wM, hM, angle || 0).map(([x, y]) => f.toLL([x, -y]));
    }

    /** Analyse un polygone : rectangle d'aire minimale (en mètres, repère écran) et superficie. */
    function analyzePolygon(latlngs) {
        const c = centroidLL(latlngs);
        const f = localFrame(c.lat, c.lng);
        const pts = latlngs.map((p) => { const xy = f.toXY(p); return [xy[0], -xy[1]]; });
        const rect = minAreaRect(pts);
        if (!rect) return null;
        return { rect, frame: f, wM: rect.w, hM: rect.h, areaM2: geodesicArea(latlngs) };
    }
    const fromScreen = (f) => ([x, y]) => f.toLL([x, -y]);

    /** Remplace un polygone par son rectangle d'aire minimale (« équerrage »). */
    function squarePolygon(latlngs) {
        const a = analyzePolygon(latlngs);
        return a ? a.rect.corners.map(fromScreen(a.frame)) : null;
    }

    /** Homothétie autour du centre pour atteindre une superficie cible (m²). */
    function scaleToArea(latlngs, currentArea, targetArea) {
        const c = centroidLL(latlngs);
        const f = localFrame(c.lat, c.lng);
        const k = Math.sqrt(targetArea / currentArea);
        return latlngs.map((p) => { const xy = f.toXY(p); return f.toLL([xy[0] * k, xy[1] * k]); });
    }

    /** Rotation autour du centre (radians, sens horaire vu du nord). */
    function rotate(latlngs, angle) {
        const c = centroidLL(latlngs);
        const f = localFrame(c.lat, c.lng);
        const co = Math.cos(angle), si = Math.sin(angle);
        return latlngs.map((p) => { const [x, y] = f.toXY(p); return f.toLL([x * co + y * si, -x * si + y * co]); });
    }

    /** Orientation (radians, convention écran) d'un segment entre deux points. */
    function bearingScreen(a, b) {
        const f = localFrame(toLL(a).lat, toLL(a).lng);
        const q = f.toXY(b);
        return Math.atan2(-q[1], q[0]);
    }

    /**
     * Découpe le rectangle d'aire minimale d'un îlot en lots de lotW × lotH mètres
     * (lots entiers uniquement, grille centrée).
     */
    function subdivide(latlngs, lotW, lotH) {
        const a = analyzePolygon(latlngs);
        if (!a) return [];
        const { cx, cy, angle, w, h } = a.rect;
        const cols = Math.floor(w / lotW + 1e-6), rows = Math.floor(h / lotH + 1e-6);
        const lots = [];
        const c = Math.cos(angle), s = Math.sin(angle);
        for (let i = 0; i < cols; i++) for (let j = 0; j < rows; j++) {
            const u = -cols * lotW / 2 + (i + 0.5) * lotW, v = -rows * lotH / 2 + (j + 0.5) * lotH;
            lots.push(rectCorners(cx + u * c - v * s, cy + u * s + v * c, lotW, lotH, angle).map(fromScreen(a.frame)));
        }
        return lots;
    }

    return {
        latLngToPixel, pixelToLatLng, metersPerPixel, utmZone, toUtm, fromUtm, toDms, geodesicArea, geodesicDistance, geodesicLength,
        convexHull, minAreaRect, polygonArea, rectCorners,
        preprocess, dominantOrientation, growRegion, closeMask, detectParcel,
        localFrame, rectangleLatLngs, analyzePolygon, squarePolygon, scaleToArea, rotate, bearingScreen, subdivide,
    };
}));
