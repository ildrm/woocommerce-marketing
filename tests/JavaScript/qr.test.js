'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const jsQR = require('../e2e/node_modules/jsqr');
const sandbox = { window: {} }; vm.createContext(sandbox); vm.runInContext(fs.readFileSync(require.resolve('../../assets/vendor/qrcode.js'), 'utf8'), sandbox);
const QR = sandbox.window.wmosQRCode; QR.stringToBytes = QR.stringToBytesFuncs['UTF-8'];
for (const url of ['https://shop.example.test/?wmos_link=08abd240aaa713756b3a5467', 'https://shop.example.test/فروشگاه/?wmos_link=08abd240aaa713756b3a5467']) {
    test('vendored QR round trip preserves tracking URL ' + (url.includes('فروشگاه') ? 'with UTF-8 store path' : 'in ASCII'), () => {
        const qr = QR(0, 'M'); qr.addData(url); qr.make(); const scale = 6, margin = 4, size = (qr.getModuleCount() + margin * 2) * scale;
        const pixels = new Uint8ClampedArray(size * size * 4).fill(255);
        for (let y = 0; y < size; ++y) for (let x = 0; x < size; ++x) {
            const row = Math.floor(y / scale) - margin, col = Math.floor(x / scale) - margin;
            if (row >= 0 && col >= 0 && row < qr.getModuleCount() && col < qr.getModuleCount() && qr.isDark(row, col)) {
                const index = (y * size + x) * 4; pixels[index] = pixels[index + 1] = pixels[index + 2] = 0;
            }
        }
        const decoded = jsQR(pixels, size, size); assert.ok(decoded); assert.equal(decoded.data, url);
        assert.match(qr.createSvgTag({cellSize:scale,margin:24,scalable:true}), /<svg[^>]+viewBox=/);
        assert.match(qr.createDataURL(4,16), /^data:image\/gif;base64,/);
        assert.equal(typeof sandbox.window.qrcode, 'undefined');
    });
}
