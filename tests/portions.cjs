'use strict';
const {scale} = require('../public/assets/portions.js');
const cases = [
    ['200 g riz', 2, '400 g riz'],
    ['1/2 tasse riz', 2, '1 tasse riz'],
    ['1 1/2 tasse riz', 2, '3 tasse riz'],
    ['½ tasse riz', 2, '1 tasse riz'],
    ['2–3 carottes', 2, '4–6 carottes'],
    ['Sel au goût', 2, 'Sel au goût'],
    ['0,5 litre', 2, '1 litre'],
];
for (const [line, factor, expected] of cases) {
    if (scale(line, factor) !== expected) throw new Error(`Quantité incorrecte : ${line}`);
}
console.log(`${cases.length} cas de portions JavaScript réussis.`);
