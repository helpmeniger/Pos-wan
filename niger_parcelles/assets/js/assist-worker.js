/* Détection en série exécutée hors du fil principal (la page reste fluide). */
/* global NPAssist */
self.importScripts('parcel-assist.js');
self.onmessage = (e) => {
    const { rgba, w, h, opt, skipBoxes } = e.data;
    const skip = (x, y) => skipBoxes.some((b) => x >= b[0] && x <= b[2] && y >= b[1] && y <= b[3]);
    const found = NPAssist.detectMany(rgba, w, h, Object.assign({}, opt, { skip }), (done, total) => self.postMessage({ progress: [done, total] }));
    self.postMessage({ done: found.map((r) => ({ corners: r.corners, area: r.area, score: r.score })) });
};
