/** Tuiles synthétiques pour les tests navigateur (aucun accès Internet nécessaire). */
'use strict';
const zlib = require('zlib');
const AS = require('../../assets/js/parcel-assist.js');
const LAT0 = 13.4205, LNG0 = 2.0185, ANG = 15 * Math.PI / 180, LOT = 20;

// ---------- Encodeur PNG minimal
const crcTable = new Int32Array(256).map((_, n) => { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; return c; });
const crc32 = (buf) => { let c = -1; for (const b of buf) c = crcTable[(c ^ b) & 0xff] ^ (c >>> 8); return (c ^ -1) >>> 0; };
function chunk(type, data) {
    const len = Buffer.alloc(4); len.writeUInt32BE(data.length);
    const td = Buffer.concat([Buffer.from(type), data]);
    const crc = Buffer.alloc(4); crc.writeUInt32BE(crc32(td));
    return Buffer.concat([len, td, crc]);
}
function png(w, h, pixel) {
    const raw = Buffer.alloc((w * 3 + 1) * h);
    for (let y = 0; y < h; y++) {
        raw[y * (w * 3 + 1)] = 0;
        for (let x = 0; x < w; x++) { const [r, g, b] = pixel(x, y); const o = y * (w * 3 + 1) + 1 + x * 3; raw[o] = r; raw[o + 1] = g; raw[o + 2] = b; }
    }
    const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(w, 0); ihdr.writeUInt32BE(h, 4); ihdr[8] = 8; ihdr[9] = 2;
    return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]);
}
let seed = 7;
const rnd = () => { seed = (seed * 1103515245 + 12345) & 0x7fffffff; return seed / 0x7fffffff; };
/** Tuile satellite synthétique : concessions de 20 m tournées de 15°, murs, toits, rues. */
function satTile(z, tx, ty) {
    const c0 = AS.latLngToPixel(LAT0, LNG0, z);
    const mpp = AS.metersPerPixel(LAT0, z);
    const co = Math.cos(ANG), si = Math.sin(ANG);
    const sands = [[205, 178, 140], [196, 170, 128], [214, 188, 150], [200, 172, 138]];
    return png(256, 256, (x, y) => {
        const mx = (tx * 256 + x - c0.x) * mpp, my = (ty * 256 + y - c0.y) * mpp;
        const u = mx * co + my * si, v = -mx * si + my * co;
        const iu = Math.floor(u / LOT + 0.5), iv = Math.floor(v / LOT + 0.5);
        const fu = u - iu * LOT, fv = v - iv * LOT;
        let col;
        if (((iv % 3) + 3) % 3 === 2) col = [150, 135, 115];
        else if (Math.abs(Math.abs(fu) - LOT / 2) < 0.4 || Math.abs(Math.abs(fv) - LOT / 2) < 0.4) col = [95, 80, 70];
        else if (fu > -7 && fu < 0 && fv > -6 && fv < 1) col = [160, 165, 170];
        else col = sands[(((iu % 4) + 4) % 4 + (((iv % 2) + 2) % 2) * 2) % 4];
        const n = (rnd() - 0.5) * 14;
        return [col[0] + n, col[1] + n, col[2] + n].map((c) => Math.max(0, Math.min(255, Math.round(c))));
    });
}
const roadTile = png(256, 256, (x, y) => (x % 64 < 3 || y % 64 < 3 ? [255, 255, 255] : [242, 239, 233]));


module.exports = { satTile, roadTile, png, LAT0, LNG0, ANG, LOT };
