import path from 'path';
import url from 'url';
import fs from 'fs';
import alias from '@rollup/plugin-alias';
import { babel } from '@rollup/plugin-babel';
import resolve from '@rollup/plugin-node-resolve';
import commonjs from '@rollup/plugin-commonjs';
import json from '@rollup/plugin-json';
import lessModules from 'rollup-plugin-less-modules';
import { terser } from 'rollup-plugin-terser';
import ini from 'ini';
import { dataToEsm } from '@rollup/pluginutils';
import evalPlugin from './eval-plugin.js';

const __dirname = path.dirname(url.fileURLToPath(import.meta.url));
const isProd = process.env.NODE_ENV === 'production';

const plugins = lessOut => [
    evalPlugin(),
    iniPlugin(),
    svgPlugin(),
    lessModules({
        output: lessOut,
        exclude: [],
    }),
    alias({
        entries: [
            { find: 'punycode', replacement: path.join(__dirname, 'node_modules/punycode2/index.js') },
        ],
    }),
    json(),
    amdDefine(),
    babel({
        presets: ['@babel/preset-env'],
        babelHelpers: 'bundled',
        exclude: ['node_modules/**'],
    }),
    resolve(),
    commonjs(),
    isProd && terser(),
].filter(x => x);

const items = [
    'account',
    'congress-loc',
    'congress-prog',
    'congress-reg',
    'delegates',
    'delegation-applications',
    'form',
    'magazines',
    'md-components',
    'registration',
];

export default items.map(item => ({
    input: `src/${item}.js`,
    preserveEntrySignatures: false,
    plugins: plugins(path.join(__dirname, `../js/dist/${item}.css`)),
    output: {
        dir: path.join(__dirname, '../js/dist/'),
        chunkFileNames: `${item}_[name].js`,
        format: 'amd',
    },
}));

function iniPlugin() {
    return {
        name: 'ini',
        transform(data, id) {
            if (id.slice(-4) !== '.ini') return null;
            try {
                const parsed = ini.parse(data);
                return {
                    code: dataToEsm(parsed, {
                        indent: '\t',
                    }),
                    map: { mappings: '' },
                };
            } catch (err) {
                this.warn({
                    message: 'Failed to parse INI',
                    id,
                    position: 0,
                });
                return null;
            }
        },
    };
}

function svgPlugin() {
    return {
        name: 'svg',
        transform(data, id) {
            if (id.slice(-4) !== '.svg') return null;
            let svg = data.replace(/<?xml[^?]?>/, '');

            return {
                code: `export default ${JSON.stringify(svg)}`,
                map: { mappings: '' },
            };
        }
    };
}

function amdDefine() {
    const define = fs.readFileSync(__dirname + '/define.js');

    return {
        id: 'amd-define',
        renderChunk (code, chunk, opts) {
            return define + code;
        },
    };
}
